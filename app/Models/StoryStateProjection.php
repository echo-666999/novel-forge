<?php

namespace App\Models;

use Database\Factories\StoryStateProjectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 可重建的不可变正式状态快照。 */
#[Fillable(['commit_id', 'novel_id', 'state_version', 'state', 'state_hash', 'created_at'])]
class StoryStateProjection extends Model
{
    /** 工厂只用于隔离测试，不触发外部请求。
     * @use HasFactory<StoryStateProjectionFactory>
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
        return ['commit_id' => 'integer', 'novel_id' => 'integer', 'state_version' => 'integer', 'state' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
