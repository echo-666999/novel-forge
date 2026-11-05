<?php

namespace App\Providers;

use App\Services\Pipeline\FakeStageExecutor;
use App\Services\Pipeline\StageExecutorInterface;
use Illuminate\Foundation\DevCommands;
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
        // 仅注册本地开发进程，不在应用启动时消费队列；每次任务使用新PHP进程加载代码。
        if ($this->app->runningInConsole() && $this->app->environment('local')) {
            DevCommands::artisan('queue:listen redis_content_acceptance --queue=content-acceptance --tries=1 --timeout=225 --memory=256 --no-interaction', 'content');
        }
    }
}
