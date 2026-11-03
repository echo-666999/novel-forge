<?php

namespace App\Jobs;

use App\Services\Pipeline\OutlineExecutor;
use App\Services\Pipeline\OutlineGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** 一个队列任务只处理一个固定章序批次，失败不会隐式重复付费。 */
class RunOutlineBatchJob implements ShouldQueue
{
    use Queueable;

    /** 自动重试关闭，失败必须显式继续。 */
    public int $tries = 1;

    /** 小于 Redis90秒重新投递间隔，大于单次60秒供应商超时。 */
    public int $timeout = 75;

    /** 载荷只含计划和归属令牌，不保存创意或凭据。 */
    public function __construct(public int $planId, public string $invocationId)
    {
        $this->onConnection('redis');
        $this->afterCommit();
    }

    /** 调用固定大纲编排，不解析用户指定类或动态任务树。 */
    public function handle(OutlineGenerationService $service, OutlineExecutor $executor): void
    {
        $service->runBatch($this->planId, $this->invocationId, $executor);
    }

    /** 进程中断时保留未知结果及前面成功章节。 */
    public function failed(?Throwable $exception): void
    {
        app(OutlineGenerationService::class)->interrupted($this->planId, $this->invocationId);
    }
}
