<?php

namespace App\Jobs;

use App\Services\Ai\AiTextGenerationService;
use App\Services\Pipeline\FormalRevisionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

/** 正式稿修订的单章单步：生成建议或事实核对，每个任务只发一次请求，失败不重发。 */
class FormalRevisionStepJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 210;

    /** 与长请求正文任务共用长超时连接与队列。 */
    public function __construct(public int $chapterId, public string $step)
    {
        $this->onConnection('redis_content_acceptance');
        $this->onQueue('content-acceptance');
        $this->afterCommit();
    }

    /** 只允许两种固定步骤。 */
    public function handle(FormalRevisionService $service, AiTextGenerationService $ai): void
    {
        match ($this->step) {
            'suggest' => $service->suggest($this->chapterId, $ai),
            'verify' => $service->verify($this->chapterId, $ai),
            default => throw new InvalidArgumentException('未知正式稿修订步骤'),
        };
    }
}
