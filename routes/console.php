<?php

use App\Services\Pipeline\NovelExportLifecycle;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 提交事务之外轮询outbox，检索投影可重复构建；此定时任务不发送付费请求。
Schedule::command('app:build-search-index')->everyMinute()->withoutOverlapping();

// 清理到期导出文件，审计与正式正文保留；独立于任何AI调用。
Artisan::command('app:prune-novel-exports', function (): void {
    $count = app(NovelExportLifecycle::class)->prune();
    $this->info('已清理过期导出文件：'.$count);
})->purpose('清理过期的私有导出文件并隔离超时导出任务');

Schedule::command('app:prune-novel-exports')->hourly()->withoutOverlapping();

// 生产运行看门狗：只做本地对账和重新投递丢失的消息，不发送或重发任何付费请求。
Schedule::command('app:watchdog')->everyMinute()->withoutOverlapping();
