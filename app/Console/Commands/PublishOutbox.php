<?php

namespace App\Console\Commands;

use App\Services\Pipeline\OutboxPublisher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** 每分钟由调度器执行：投递正式提交通知，更新检索投影后写投递时间。 */
#[Signature('app:publish-outbox {--without-embeddings : 本轮不自动补向量，只更新本地词法索引} {--limit=500 : 本轮最多处理的通知数}')]
#[Description('投递正式提交通知：更新检索投影并标记投递时间，按设置自动补向量')]
class PublishOutbox extends Command
{
    /** 输出本轮投递结果，便于在日志中追溯；有小说投影失败时返回失败码。 */
    public function handle(OutboxPublisher $publisher): int
    {
        $result = $publisher->publish(! $this->option('without-embeddings'), (int) $this->option('limit'));
        $this->line(json_encode(['published' => count($result['published']), 'pending' => count($result['pending']), ...collect($result)->except(['published', 'pending'])->all()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $result['failed_novels'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
