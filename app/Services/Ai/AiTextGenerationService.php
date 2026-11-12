<?php

namespace App\Services\Ai;

use App\Models\ContextManifest;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Services\Pipeline\ContextBuilder;
use App\Services\Pipeline\NovelBudget;
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
    /** 注入互不污染的固定供应商适配器，Fake 开关不会修改 SDK 全局状态。 */
    public function __construct(private FakeTextProvider $fake, private OpenAiTextProvider $real, private CostEstimator $estimator, private DeepSeekTextProvider $deepSeek) {}

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
        // 供应商由模型配置决定，未登记模型按名称前缀识别；切换默认模型不会改变既有运行的供应商。
        $provider = $mode === 'fake' ? 'fake' : ModelProfiles::provider($model);
        // 仅读取小说根运行的冻结模板；历史运行没有此字段时继续使用原程序指令。
        if ($novelId !== null && $options->isStructured()) {
            $config = Novel::findOrFail($novelId)->generation_config ?? [];
            if (isset($config['prompt_templates'])) {
                $options = $options->withTemplates($config['prompt_templates'], $config['prompt_variables']);
            }
        }
        // 费用审计记录最终模型参数，避免将已移除的温度误记为实际发送。
        $parameters = $provider === 'deepseek' ? SingleAttemptDeepSeekGateway::modelParameters($options->toArray(), $options->executionContext['deepseek_reasoning_effort'] ?? $options->submissionContext['deepseek_reasoning_effort'] ?? 'none') : SingleAttemptOpenAiGateway::modelParameters($options->toArray(), $model);
        if ($options->templateSnapshot !== null) {
            $parameters['prompt_templates'] = $options->templateSnapshot;
        }
        if ($options->settingDecision || $options->chapterStep !== null || $options->submissionPurpose !== null || $options->formalRevisionPurpose !== null || $options->templateSnapshot !== null) {
            // 同一不可变选项构造实际Agent和调用前审计；写入失败时绝不发送HTTP。
            $agent = match (true) {
                $options->settingDecision => new SettingDecisionAgent($options),
                $options->formalRevisionPurpose !== null => new FormalRevisionAgent($options),
                $options->submissionPurpose !== null => new SubmissionAgent($options),
                $options->chapterStep !== null => new ChapterGenerationAgent($options),
                $options->outlineRange !== null => new OutlineGenerationAgent($options),
                default => new SettingGenerationAgent($options),
            };
            $schema = (new JsonSchemaTypeFactory)->object($agent->schema(new JsonSchemaTypeFactory))->withoutAdditionalProperties()->toArray();
            // C04先组装并持久化清单，阻塞时不建立成本流水、不发送供应商请求。
            $context = $options->submissionContext ?? $options->executionContext;
            if (isset($context['context_policy'])) {
                $marker = $options->submissionPurpose !== null ? '冻结提交输入：' : '冻结输入：';
                $parts = explode($marker, $prompt, 2);
                if (count($parts) !== 2 || $novelId === null) {
                    throw new InvalidArgumentException('上下文组装缺少小说或冻结输入');
                }
                $document = app(ContextBuilder::class)->build(json_decode($parts[1], true, flags: JSON_THROW_ON_ERROR), $parts[0].$marker, $agent->instructions(), $schema, $context['context_policy'], $options->maxOutputTokens, $context['context_source']);
                $manifest = ContextManifest::create(['novel_id' => $novelId, 'chapter_number' => $context['context_source']['chapter_number'], 'purpose' => $options->submissionPurpose ?? $options->chapterStep, 'status' => $document['status'], 'document' => $document, 'document_hash' => PlanningJson::hash($document), 'created_at' => now()]);
                if ($document['status'] === 'blocked') {
                    throw new AiGenerationException('上下文容量不足，清单 #'.$manifest->id.' 已保留，尚未发送请求。', 'context_capacity');
                }
                $prompt = $document['user_prompt'];
                $parameters['context_manifest_id'] = $manifest->id;
                $parameters['context_manifest_hash'] = $manifest->document_hash;
            }
            $parameters['prompt_audit'] = ['system_prompt' => $agent->instructions(), 'user_prompt' => $prompt,
                'response_schema' => $schema, 'parameters' => $parameters,
                'system_hash' => hash('sha256', $agent->instructions()), 'user_hash' => hash('sha256', $prompt),
                'schema_hash' => PlanningJson::hash($schema)];
        }
        // 必须在调用前写入数据库；写入失败直接停止，避免产生没有本地记录的费用。
        // 设了预算的书在同一事务内锁定小说并预留本次上限金额，超出时在发送前拒绝。
        $inputEstimate = mb_strlen($prompt) + mb_strlen((string) data_get($parameters, 'prompt_audit.system_prompt', ''));
        $log = DB::transaction(fn (): CostLog => $this->createPendingLog($mode, $provider, $model, $novelId, $novelStageId, $options, $promptVersion, $prompt, $parameters,
            $mode === 'real' && $novelId !== null ? app(NovelBudget::class)->reserve($novelId, $model, $inputEstimate, $options->maxOutputTokens) : null));
        $started = hrtime(true);
        // 在外部调用前冻结整个单价集合（后台登记与配置合并），运行中登记新单价不影响本次计价。
        $prices = app(ModelPrices::class)->snapshot();
        try {
            $result = (match ($provider) {
                'fake' => $this->fake, 'deepseek' => $this->deepSeek, default => $this->real
            })->generate($prompt, $model, $options);
            $log->fill($this->resultAttributes($result));
            $this->validateUsage($result);
            // 先按实际响应模型匹配，未登记时按请求模型匹配（同名带日期后缀的响应模型）。
            $price = ModelPrices::resolve($prices, $result->model) ?? ModelPrices::resolve($prices, $model);
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

    /** 写入待返回记录，可附带预算预留；路由版本规则与原实现一致。
     * @param  array<string, mixed>  $parameters
     * @param  array{amount: string, currency: string}|null  $reservation
     */
    private function createPendingLog(string $mode, string $provider, string $model, ?int $novelId, ?int $novelStageId, TextRequestOptions $options, ?string $promptVersion, string $prompt, array $parameters, ?array $reservation): CostLog
    {
        return CostLog::query()->create([
            'request_uuid' => (string) Str::uuid7(), 'mode' => $mode, 'requested_provider' => $provider,
            'requested_model' => $model, 'novel_id' => $novelId, 'novel_stage_id' => $novelStageId,
            'route_version' => $mode === 'real' ? ($options->submissionPurpose !== null ? 'submission-'.$provider.'-'.$options->submissionPurpose.'-v1' : ($options->chapterStep !== null ? 'b04-'.$provider.'-'.$options->chapterStep.'-'.Str::afterLast($options->chapterVersion(), '-') : ($options->outlineRange !== null ? 'b03-'.$provider.'-outline-v1' : ($options->structuredSetting ? 'b02-'.$provider.'-setting-v1' : 'a03-'.$provider.'-v1')))) : 'a03-fake-v1', 'prompt_version' => $promptVersion,
            'prompt_hash' => hash('sha256', $prompt), 'request_parameters' => $parameters,
            'status' => 'pending', 'billing_status' => $mode === 'fake' ? 'simulated' : 'unknown', 'started_at' => now(),
            'reserved_cost' => $reservation['amount'] ?? null, 'reserved_currency' => $reservation['currency'] ?? null,
        ]);
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
