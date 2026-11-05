<?php

namespace App\Services\Ai;

use App\Models\CostLog;
use App\Models\NovelStage;
use App\Services\Pipeline\PlanningJson;
use App\Settings\GeneralSettings;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/** 文本调用统一入口；先保存意图，再调用供应商，最后保存结果和计量证据。 */
final class AiTextGenerationService
{
    /** 注入两个互不污染的适配器，Fake 开关不会修改 SDK 全局状态。 */
    public function __construct(private FakeTextProvider $fake, private OpenAiTextProvider $real, private CostEstimator $estimator) {}

    /** 执行一次调用并返回持久化的统一结果；任何失败都不自动重试。 */
    public function generate(string $prompt, string $mode = 'fake', ?string $model = null, ?int $novelId = null, ?int $novelStageId = null, ?string $promptVersion = null, ?TextRequestOptions $options = null): CostLog
    {
        $options ??= TextRequestOptions::probe();
        if ($options->isStructured() && $mode !== 'real') {
            throw new InvalidArgumentException('结构化设定必须走显式真实调用路径');
        }
        if (! in_array($mode, ['fake', 'real'], true) || trim($prompt) === '') {
            throw new InvalidArgumentException('调用模式必须为 fake 或 real，提示内容不能为空。');
        }
        if ($mode === 'real' && DB::connection()->transactionLevel() > 0) {
            throw new InvalidArgumentException('真实调用不能处于数据库事务中，避免请求前的成本记录被回滚。');
        }
        if ($novelStageId !== null) {
            $stage = NovelStage::query()->findOrFail($novelStageId);
            if ($novelId !== null && $stage->novel_id !== $novelId) {
                throw new InvalidArgumentException('阶段与小说不匹配，尚未发送请求。');
            }
            $novelId = $stage->novel_id;
        }
        $model = $mode === 'fake' ? 'a03-fake-v1' : trim($model ?? app(GeneralSettings::class)->default_model);
        if ($model === '' || strlen($model) > 128) {
            throw new InvalidArgumentException('模型标识不能为空且不能超过128字节。');
        }
        $parameters = $options->toArray();
        if ($options->chapterStep !== null || $options->submissionPurpose !== null) {
            // 同一不可变选项构造实际Agent和调用前审计；写入失败时绝不发送HTTP。
            $agent = $options->submissionPurpose !== null ? new SubmissionAgent($options) : new ChapterGenerationAgent($options);
            $schema = (new JsonSchemaTypeFactory)->object($agent->schema(new JsonSchemaTypeFactory))->withoutAdditionalProperties()->toArray();
            $parameters['prompt_audit'] = ['system_prompt' => $agent->instructions(), 'user_prompt' => $prompt,
                'response_schema' => $schema, 'parameters' => $options->toArray(),
                'system_hash' => hash('sha256', $agent->instructions()), 'user_hash' => hash('sha256', $prompt),
                'schema_hash' => PlanningJson::hash($schema)];
        }
        // 必须在调用前写入数据库；写入失败直接停止，避免产生没有本地记录的费用。
        $log = CostLog::query()->create([
            'request_uuid' => (string) Str::uuid7(), 'mode' => $mode, 'requested_provider' => $mode === 'real' ? 'openai' : 'fake',
            'requested_model' => $model, 'novel_id' => $novelId, 'novel_stage_id' => $novelStageId,
            'route_version' => $mode === 'real' ? ($options->submissionPurpose !== null ? 'submission-openai-'.$options->submissionPurpose.'-v1' : ($options->chapterStep !== null ? 'b04-openai-'.$options->chapterStep.'-'.Str::afterLast($options->chapterVersion(), '-') : ($options->outlineRange !== null ? 'b03-openai-outline-v1' : ($options->structuredSetting ? 'b02-openai-setting-v1' : 'a03-openai-v1')))) : 'a03-fake-v1', 'prompt_version' => $promptVersion,
            'prompt_hash' => hash('sha256', $prompt), 'request_parameters' => $parameters,
            'status' => 'pending', 'billing_status' => $mode === 'fake' ? 'simulated' : 'unknown', 'started_at' => now(),
        ]);
        $started = hrtime(true);
        // 在外部调用前冻结整个价格集合，运行中配置变化不影响本次计价。
        $prices = config('ai.costs.prices', []);
        try {
            $result = ($mode === 'fake' ? $this->fake : $this->real)->generate($prompt, $model, $options);
            $log->fill($this->resultAttributes($result));
            $this->validateUsage($result);
            $price = $result->model !== null && is_array($prices[$result->model] ?? null) ? $prices[$result->model] : null;
            $log->fill($this->estimator->estimate($result, $mode, $price));
            $log->status = 'succeeded';
        } catch (AiGenerationException $exception) {
            if ($exception->result !== null) {
                $log->fill($this->resultAttributes($exception->result));
            }
            if ($exception->category === 'invalid_usage') {
                // 非法数值不能写入受约束计量列；完整原始值仍保留在脱敏响应中。
                $log->fill(array_fill_keys(['input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens'], null));
            }
            $log->fill(['status' => $exception->status, 'error_category' => $exception->category, 'error_message' => $exception->getMessage()]);
            $log->fill(['duration_ms' => (int) ((hrtime(true) - $started) / 1000000), 'finished_at' => now()])->saveOrFail();
            throw new AiGenerationException($exception->getMessage(), $exception->category, $exception->status, costLogId: $log->id);
        } catch (Throwable) {
            $log->fill(['status' => 'unknown', 'error_category' => 'unexpected_error', 'error_message' => '调用结果无法确认，请检查本地记录；未自动重发。', 'duration_ms' => (int) ((hrtime(true) - $started) / 1000000), 'finished_at' => now()])->saveOrFail();
            throw new AiGenerationException('调用结果无法确认，请检查本地记录；未自动重发。', 'unexpected_error', 'unknown', costLogId: $log->id);
        }
        // 结果保存失败必须向调用方报错，不能把仅在内存中的输出宣称为验收成功。
        $log->fill(['duration_ms' => (int) ((hrtime(true) - $started) / 1000000), 'finished_at' => now()])->saveOrFail();

        return $log->refresh();
    }

    /** 提取统一结果；不覆盖请求前冻结的模型与路由快照。
     * @return array<string, mixed>
     */
    private function resultAttributes(TextGenerationResult $result): array
    {
        return [...$result->usage, 'provider' => $result->provider, 'model' => $result->model, 'provider_request_id' => $result->providerRequestId, 'response_text' => $result->text, 'structured_output' => $result->structured, 'raw_response' => $result->rawResponse];
    }

    /** 拒绝负数或不可能的子集计量，不修改供应商原始响应证据。 */
    private function validateUsage(TextGenerationResult $result): void
    {
        $usage = $result->usage;
        $negative = collect($usage)->contains(static fn (?int $value): bool => $value !== null && $value < 0);
        if ($negative || ($usage['input_tokens'] !== null && ($usage['cache_read_tokens'] ?? 0) + ($usage['cache_write_tokens'] ?? 0) > $usage['input_tokens']) || ($usage['output_tokens'] !== null && ($usage['reasoning_tokens'] ?? 0) > $usage['output_tokens'])) {
            throw new AiGenerationException('供应商计量数据不合法，已保留原始响应。', 'invalid_usage');
        }
    }
}
