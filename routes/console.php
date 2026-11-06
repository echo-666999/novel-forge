<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 提交事务之外轮询outbox，检索投影可重复构建；此定时任务不发送付费请求。
Schedule::command('app:build-search-index')->everyMinute()->withoutOverlapping();
