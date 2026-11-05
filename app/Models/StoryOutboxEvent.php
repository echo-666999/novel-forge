<?php

namespace App\Models;

use Database\Factories\StoryOutboxEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 事务内提交通知，投递状态可独立更新。 */
#[Fillable(['commit_id', 'event_type', 'payload', 'created_at', 'published_at'])]
class StoryOutboxEvent extends Model
{
    /** 工厂只用于隔离测试，不触发外部请求。
     * @use HasFactory<StoryOutboxEventFactory>
     */
    use HasFactory;

    public $timestamps = false;

    /** 禁止覆盖历史；可变运行只允许状态和消费计数演进。 */
    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), ['published_at']) !== []) {
                throw new LogicException('事务通知正文不可覆盖');
            }
        });
        static::deleting(fn () => throw new LogicException('故事提交历史不可删除'));
    }

    /** 保留结构化快照、整数和不可变时间类型。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['commit_id' => 'integer', 'payload' => 'array', 'created_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime'];
    }
}
