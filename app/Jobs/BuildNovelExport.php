<?php

namespace App\Jobs;

use App\Services\Pipeline\NovelExportLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** 专用无模型导出任务，失败不隐式重新生成。 */
class BuildNovelExport implements ShouldQueue
{
    use Queueable;

    /** 文件生成最多执行一次，重新生成使用新审计任务。 */
    public int $tries = 1;

    /** 小于数据库队列重新可见时限。 */
    public int $timeout = 60;

    /** 固定专用队列，不混入正文付费队列。 */
    public function __construct(public int $exportId)
    {
        $this->onConnection('database')->onQueue('novel-exports')->afterCommit();
    }

    /** 由生命周期服务校验清单和来源后构建私有文件。 */
    public function handle(NovelExportLifecycle $service): void
    {
        $service->build($this->exportId);
    }

    /** 超时或运行中断保存失败状态，不泄露异常中的路径及配置。 */
    public function failed(?Throwable $exception): void
    {
        app(NovelExportLifecycle::class)->fail($this->exportId, '导出任务中断，请查看来源后按冻结清单重新生成。');
    }
}
