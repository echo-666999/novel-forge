<?php

namespace App\Jobs;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Models\CostLog;
use App\Models\Novel;
use App\Services\Pipeline\NovelPipelineService;
use App\Services\Pipeline\SettingExecutor;
use App\Services\Pipeline\StageExecutorRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** 真实设定专用任务；失败和未知结果均不触发队列自动重试。 */
class RunSettingJob implements ShouldQueue
{
    use Queueable;

    /** 重试必须由显式命令创建新尝试。 */
    public int $tries = 1;

    /** 留出请求45秒以外的记账时间，并小于 Redis 的90秒投递间隔。 */
    public int $timeout = 60;

    /** 请求令牌限制迟到消息及失败回调的归属，不携带创意或凭据。 */
    public function __construct(public int $novelId, public string $invocationId)
    {
        $this->onConnection('redis');
        $this->afterCommit();
    }

    /** 显式选取真实设定执行器，不改变全局模拟绑定。 */
    public function handle(SettingExecutor $executor): void
    {
        $novel = Novel::query()->find($this->novelId);
        if ($novel === null || $novel->generation_mode !== GenerationMode::SettingReal || ($novel->current_stage !== null && $novel->current_stage !== Stage::Setting)) {
            return;
        }
        $pipeline = new NovelPipelineService(new StageExecutorRegistry($executor, true));
        $pipeline->run($novel, invocationId: $this->invocationId);
    }

    /** 超时或未捕获故障保留未知结果，只结算当前令牌实际拥有的 running 尝试。 */
    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $novel = Novel::query()->lockForUpdate()->find($this->novelId);
            if ($novel === null || $novel->generation_mode !== GenerationMode::SettingReal || ($novel->current_stage !== null && $novel->current_stage !== Stage::Setting) || data_get($novel->generation_config, 'invocation_id') !== $this->invocationId) {
                return;
            }
            $stage = $novel->stages()->where('status', StageStatus::Running->value)->lockForUpdate()->latest('id')->first();
            if ($stage !== null && data_get($stage->input, 'invocation_id') === $this->invocationId) {
                $log = CostLog::query()->where('novel_stage_id', $stage->id)->latest('id')->first();
                // pending 明细只能变成 unknown；已经保存的响应及用量不能被回调覆盖。
                if ($log?->status === 'pending') {
                    $log->update(['status' => 'unknown', 'error_category' => 'queue_interrupted', 'error_message' => '队列执行中断，供应商结果无法确认；未自动重发。', 'finished_at' => now()]);
                }
                $stage->update(['status' => StageStatus::Failed, 'output' => ['simulation' => false, 'stage' => 'setting', 'data' => ['cost_log_id' => $log?->id, 'outcome' => 'unknown', 'error_category' => 'queue_interrupted']], 'error_message' => '设定任务中断，结果未知；请先核查费用记录。', 'finished_at' => now()]);
                $novel->update(['status' => $novel->execution_count >= $novel->max_execution_count ? NovelStatus::Paused : NovelStatus::Error]);
            } elseif ($novel->status === NovelStatus::Pending && $stage === null) {
                $novel->update(['status' => NovelStatus::Error]);
            }
        });
        Log::warning('真实设定任务中断', ['novel_id' => $this->novelId, 'error_category' => $exception === null ? 'unknown' : class_basename($exception)]);
    }
}
