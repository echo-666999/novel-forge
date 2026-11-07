<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 实体设定与初始关系的不可变作者版本，不修改正式事件。 */
#[Fillable(['novel_id', 'entity_id', 'parent_id', 'status', 'definition', 'content_hash', 'effective_from', 'reason', 'actor_id', 'created_at'])]
class EntityDefinitionVersion extends Model
{
    /** 版本只有创建时间。 */
    public $timestamps = false;

    /** 修订和批准均新增记录，禁止覆盖或删除。 */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('实体设定版本不可覆盖'));
        static::deleting(fn () => throw new LogicException('实体设定版本不可删除'));
    }

    /** 结构及时间使用明确类型。 */
    protected function casts(): array
    {
        return ['novel_id' => 'integer', 'entity_id' => 'integer', 'parent_id' => 'integer', 'definition' => 'array', 'effective_from' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
