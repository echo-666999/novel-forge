<?php

namespace App\Providers;

use App\Services\Pipeline\FakeStageExecutor;
use App\Services\Pipeline\StageExecutorInterface;
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
        //
    }
}
