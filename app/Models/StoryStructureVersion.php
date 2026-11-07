<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 不可覆盖的卷弧草稿与冻结版本。 */
#[Fillable(['novel_id', 'parent_id', 'status', 'structure', 'content_hash', 'reason', 'actor_id', 'created_at'])]
class StoryStructureVersion extends Model
{
    /** 隔离测试工厂。 */
    use HasFactory;

    /** 版本仅记录创建时间。 */
    public $timestamps = false;

    /** 修订与冻结都生成新版本，不修改旧内容。 */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('卷弧版本不可覆盖'));
        static::deleting(fn () => throw new LogicException('卷弧历史不可删除'));
    }

    /** 保存结构及来源的明确类型。 */
    protected function casts(): array
    {
        return ['structure' => 'array', 'novel_id' => 'integer', 'parent_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
