<?php

namespace App\Jobs;

use App\Services\Pipeline\ChapterSubmissionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** 提交准备一条消息只执行一个模型用途，失败不自动重发。 */
class PrepareChapterSubmissionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 330;

    /** 沿用长文专用连接，重现间隔大于供应商与Job超时。 */
    public function __construct(public int $runId, public string $invocationId)
    {
        $this->onConnection('redis_content_acceptance')->onQueue('content-acceptance')->afterCommit();
    }

    /** 调用唯一准备服务，不动态选择执行类。 */
    public function handle(ChapterSubmissionService $service): void
    {
        $service->execute($this->runId, $this->invocationId);
    }

    /** 保留未知请求，不重新写正文。 */
    public function failed(?Throwable $exception): void
    {
        app(ChapterSubmissionService::class)->interrupted($this->runId, $this->invocationId);
    }
}
