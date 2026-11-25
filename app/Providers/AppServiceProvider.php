<?php

namespace App\Providers;

use App\Services\Ai\ProviderCredentials;
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
        $this->app->singleton(ProviderCredentials::class);
    }

    /**
     * 保留应用启动扩展点，阶段流转不在启动过程中执行。
     */
    public function boot(): void
    {
        // 后台维护的供应商密钥与地址优先于本机环境变量；设置表未就绪时保持环境变量。
        app(ProviderCredentials::class)->apply();
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
        // 调度器与各队列消费者由本机常驻服务（launchd：local.novelforge.scheduler、worker-*）运行，
        // 开发入口不再重复启动，避免同一任务被两套进程领取；只保留网页、日志与前端构建。
        if ($this->app->runningInConsole() && $this->app->environment('local')) {
            DevCommands::except('queue');
        }
    }
}
