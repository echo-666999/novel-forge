<?php

namespace App\Models;

use Database\Factories\StoryEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 不可变正式事件及正文证据。 */
#[Fillable(['commit_id', 'novel_id', 'chapter_id', 'version_id', 'actor_entity_id', 'event_key', 'narrative_order', 'event_type', 'fact_category', 'payload', 'evidence', 'created_at'])]
class StoryEvent extends Model
{
    /** 工厂只用于隔离测试，不触发外部请求。
     * @use HasFactory<StoryEventFactory>
     */
    use HasFactory;

    public $timestamps = false;

    /** 禁止覆盖历史；可变运行只允许状态和消费计数演进。 */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('正式或候选审计产物不可覆盖'));
        static::deleting(fn () => throw new LogicException('故事提交历史不可删除'));
    }

    /** 保留结构化快照、整数和不可变时间类型。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['commit_id' => 'integer', 'novel_id' => 'integer', 'chapter_id' => 'integer', 'version_id' => 'integer', 'actor_entity_id' => 'integer', 'narrative_order' => 'integer', 'payload' => 'array', 'evidence' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
