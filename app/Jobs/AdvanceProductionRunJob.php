<?php

namespace App\Jobs;

use App\Models\ProductionRun;
use App\Services\Pipeline\ProductionRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** 每条消息只推进生产运行的一个持久化断点，成功后再投递下一步。 */
class AdvanceProductionRunJob implements ShouldQueue
{
    use Queueable;

    /** 未知请求不由队列自动重发。 */
    public int $tries = 1;

    /** 大于扩展请求300秒HTTP上限，小于连接360秒可见性超时。 */
    public int $timeout = 330;

    /** 编排编号来自持久化授权，不接受客户端动态步骤。 */
    public function __construct(public int $runId)
    {
        $this->onConnection('redis_content_acceptance');
        $this->onQueue('content-acceptance');
        $this->afterCommit();
    }

    /** 失败停留在可审计断点，重复消息不会重领运行中或完成记录。 */
    public function handle(ProductionRunService $service): void
    {
        $run = $service->advance($this->runId);
        if ($run->status === 'pending') {
            $service->dispatch($run);
        }
    }

    /** 任务超时保留领取状态，禁止把未知付费自动当作未执行。 */
    public function failed(?Throwable $exception): void
    {
        ProductionRun::whereKey($this->runId)->where('status', 'running')->update(['error' => 'WORKER_INTERRUPTED_REQUIRES_RECONCILIATION']);
    }
}
