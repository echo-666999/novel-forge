<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 验收来源只读，状态仅是追加证据的展示投影。 */
#[Fillable(['novel_id', 'actor_id', 'kind', 'end_chapter', 'snapshot', 'snapshot_hash', 'status', 'created_at'])]
class AcceptanceBatch extends Model
{
    /** 不维护可变更新时间。 */
    public $timestamps = false;

    /** 禁止修改冻结字段或删除批次。 */
    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), ['status']) !== []) {
                throw new LogicException('验收来源和政策不可覆盖');
            }
        });
        static::deleting(fn () => throw new LogicException('验收批次不可删除'));
    }

    /** 固定快照、编号和时间类型。 */
    protected function casts(): array
    {
        return ['novel_id' => 'integer', 'actor_id' => 'integer', 'end_chapter' => 'integer', 'snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
