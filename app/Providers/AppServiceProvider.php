<?php

namespace App\Providers;

use App\Services\Pipeline\FakeStageExecutor;
use App\Services\Pipeline\StageExecutorInterface;
use App\Services\Pipeline\WorkerHeartbeat;
use Illuminate\Foundation\DevCommands;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/** 注册应用服务，A05 阶段执行器固定为纯假数据实现。 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * 绑定固定阶段契约；不根据用户输入选择任意执行器类。
     */
    public function register(): void
    {
        $this->app->bind(StageExecutorInterface::class, FakeStageExecutor::class);
    }

    /**
     * 保留应用启动扩展点，阶段流转不在启动过程中执行。
     */
    public function boot(): void
    {
        // 只有真实消费者循环或领取任务才上报，HTTP页面读取不会制造心跳。
        Queue::looping(function (Looping $event): void {
            app(WorkerHeartbeat::class)->observe($event->connectionName, $event->queue);
        });
        Queue::before(function (JobProcessing $event): void {
            // 同步队列可能在HTTP内执行，不能把它当作独立消费者心跳。
            if ($event->job instanceof SyncJob) {
                return;
            }
            app(WorkerHeartbeat::class)->observe($event->connectionName, $event->job->getQueue(), true);
        });
        // 仅注册本地开发进程，不在应用启动时消费队列；每次任务使用新PHP进程加载代码。
        if ($this->app->runningInConsole() && $this->app->environment('local')) {
            // 导出使用独立无付费队列，随标准开发入口启动消费者。
            DevCommands::artisan('queue:listen database --queue=novel-exports --tries=1 --timeout=75 --memory=256 --no-interaction', 'exports');
            DevCommands::artisan('queue:listen redis_content_acceptance --queue=content-acceptance --tries=1 --timeout=225 --memory=256 --no-interaction', 'content');
        }
    }
}
