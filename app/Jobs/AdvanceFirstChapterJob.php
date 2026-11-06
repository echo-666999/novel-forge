<?php

namespace App\Jobs;

use App\Models\FirstChapterRun;
use App\Services\Pipeline\FirstChapterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** 每条消息只推进一个持久化断点，成功后再投递下一步。 */
class AdvanceFirstChapterJob implements ShouldQueue
{
    use Queueable;

    /** 未知请求不由队列自动重发。 */
    public int $tries = 1;

    /** 大于供应商180秒上限，小于连接240秒可见性超时。 */
    public int $timeout = 210;

    /** 编排编号来自持久化授权，不接受客户端动态步骤。 */
    public function __construct(public int $runId)
    {
        $this->onConnection('redis_content_acceptance');
        $this->onQueue('content-acceptance');
        $this->afterCommit();
    }

    /** 失败停留在可审计断点，重复消息不会重领运行中或完成记录。 */
    public function handle(FirstChapterService $service): void
    {
        $run = $service->advance($this->runId);
        if ($run->status === 'pending') {
            $service->dispatch($run);
        }
    }

    /** 任务超时保留领取状态，禁止把未知付费自动当作未执行。 */
    public function failed(?Throwable $exception): void
    {
        FirstChapterRun::whereKey($this->runId)->where('status', 'running')->update(['error' => 'WORKER_INTERRUPTED_REQUIRES_RECONCILIATION']);
    }
}
