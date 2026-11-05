<?php

namespace App\Jobs;

use App\Services\Ai\AiTextGenerationService;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\ProgressionChecker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** 每个首章消息只执行一个固定步骤和一次供应商请求。 */
class RunChapterStepJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75;

    /** 普通任务保持75秒；持续验收单独使用长超时连接，不改变旧消息。 */
    public function __construct(public int $runId, public string $invocationId, bool $extendedTimeout = false)
    {
        $this->onConnection($extendedTimeout ? 'redis_content_acceptance' : 'redis');
        if ($extendedTimeout) {
            $this->timeout = 210;
            $this->onQueue('content-acceptance');
        }
        $this->afterCommit();
    }

    /** 执行编排服务，不能动态解析任意步骤或模型工具。 */
    public function handle(ContentGenerationService $service, AiTextGenerationService $ai, ProgressionChecker $checker): void
    {
        $service->runStep($this->runId, $this->invocationId, $ai, $checker);
    }

    /** 超时不重发，保留此前成功正文和调用记录。 */
    public function failed(?Throwable $exception): void
    {
        app(ContentGenerationService::class)->interrupted($this->runId, $this->invocationId);
    }
}
