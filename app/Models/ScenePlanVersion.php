<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 作者场景规划的不可变版本，冻结只作用于未来新运行。 */
#[Fillable(['novel_id', 'outline_chapter_id', 'parent_id', 'status', 'scenes', 'source_hash', 'content_hash', 'reason', 'actor_id', 'created_at'])]
class ScenePlanVersion extends Model
{
    /** 历史版本不设置更新时间。 */
    public $timestamps = false;

    /** 拆分、合并、修订和冻结都必须新增版本。 */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('场景规划历史不可覆盖'));
        static::deleting(fn () => throw new LogicException('场景规划历史不可删除'));
    }

    /** 固定数据库结构与编号转换。 */
    protected function casts(): array
    {
        return ['novel_id' => 'integer', 'outline_chapter_id' => 'integer', 'parent_id' => 'integer', 'scenes' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
