<?php

namespace App\Services\Pipeline;

use App\Enums\GenerationMode;
use App\Enums\Stage;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Services\Ai\AiGenerationException;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\TextRequestOptions;
use InvalidArgumentException;
use Throwable;

/** 只执行一次真实大纲请求，输出是候选，不能代表作者批准或正式状态。 */
final class OutlineExecutor implements StageExecutorInterface
{
    /** 复用既有先记账后请求的统一调用入口。 */
    public function __construct(private AiTextGenerationService $ai) {}

    /** 使用领用时冻结的输入，先保留响应和用量，再做严格结构校验。 */
    public function execute(Novel $novel, NovelStage $stage): StageResult
    {
        if ($novel->generation_mode !== GenerationMode::SettingReal || $stage->stage !== Stage::Outline || $stage->novel_id !== $novel->id || data_get($stage->input, 'simulation') !== false || data_get($stage->input, 'pipeline.version') !== OutlineSchema::VERSION) {
            throw new InvalidArgumentException('真实大纲执行器不能接管其他阶段或模拟小说');
        }
        $log = null;
        try {
            // 同一阶段已有调用证据时拒绝重发；重试必须创建新的阶段尝试。
            if (CostLog::query()->where('novel_stage_id', $stage->id)->exists()) {
                throw new InvalidArgumentException('本阶段已有调用记录，不能重复发送');
            }
            $config = $stage->input['generation_config'];
            $range = $stage->input['range'];
            $options = TextRequestOptions::outline($config['temperature'], $range['start'], $range['end'], $range['total'], $config['timeout_seconds'] ?? 60);
            if (($config['max_output_tokens'] ?? null) !== $options->maxOutputTokens || ($config['timeout_seconds'] ?? null) !== $options->timeoutSeconds || ($config['schema_version'] ?? null) !== OutlineSchema::VERSION || ($config['prompt_version'] ?? null) !== 'b03-outline-prompt-v1') {
                throw new InvalidArgumentException('冻结的请求配置与当前大纲版本不一致');
            }
            $prompt = '请按范围生成大纲候选。冻结输入 JSON：'.PlanningJson::encode(collect($stage->input)->only(['novel', 'confirmed_setting', 'decisions', 'range', 'roadmap', 'previous_chapters', 'handoff'])->all());
            $log = $this->ai->generate($prompt, 'real', $config['model'], $novel->id, $stage->id, $config['prompt_version'], $options);
            $batch = OutlineSchema::parse($log->response_text, $range['start'], $range['end'], $range['total']);

            return StageResult::completed([
                'schema_version' => OutlineSchema::VERSION, 'candidate_status' => 'candidate',
                ...$batch, 'cost_log_id' => $log->id,
            ], self::cost($log));
        } catch (AiGenerationException $exception) {
            $log = $exception->costLogId === null ? null : CostLog::query()->find($exception->costLogId);

            return StageResult::failed($exception->getMessage(), [
                'schema_version' => OutlineSchema::VERSION, 'cost_log_id' => $log?->id,
                'error_category' => $exception->category, 'outcome' => $exception->status,
            ], self::cost($log));
        } catch (Throwable $exception) {
            // 保存失败可能发生在供应商已经响应之后，不能把未知结果当作可安全重发。
            $log ??= CostLog::query()->where('novel_stage_id', $stage->id)->latest('id')->first();
            $schemaFailure = $exception instanceof InvalidArgumentException && $log?->status === 'succeeded';

            return StageResult::failed($schemaFailure ? '大纲响应未通过结构校验，原始响应及费用证据已保留。' : '大纲执行结果无法确认，请检查调用记录；未自动重发。', [
                'schema_version' => OutlineSchema::VERSION, 'cost_log_id' => $log?->id,
                'error_category' => $schemaFailure ? 'invalid_outline_schema' : 'outline_execution',
                'outcome' => $schemaFailure ? 'failed' : 'unknown',
            ], self::cost($log));
        }
    }

    /** 将持久化计费可信度带回阶段，不把真实未知金额改为模拟零费用。
     * @return array{billing_status: string, currency: ?string, estimated_cost: ?string, actual_cost: ?string}|null
     */
    private static function cost(?CostLog $log): ?array
    {
        return $log === null ? null : $log->only(['billing_status', 'currency', 'estimated_cost', 'actual_cost']);
    }
}
