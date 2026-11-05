<?php

namespace App\Models;

use Database\Factories\StoryEntityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 已批准的不可变实体定义。 */
#[Fillable(['novel_id', 'type', 'name', 'normalized_name', 'aliases', 'definition', 'definition_hash', 'origin', 'approved_by', 'approved_at'])]
class StoryEntity extends Model
{
    /** 工厂只用于隔离测试，不触发外部请求。
     * @use HasFactory<StoryEntityFactory>
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
        return ['novel_id' => 'integer', 'aliases' => 'array', 'definition' => 'array', 'origin' => 'array', 'approved_at' => 'immutable_datetime'];
    }
}
