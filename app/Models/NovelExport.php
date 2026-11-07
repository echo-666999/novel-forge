<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 冻结导出来源，只允许执行状态和追加审计演进。 */
#[Fillable(['uuid', 'novel_id', 'actor_id', 'parent_id', 'format', 'end_chapter', 'snapshot', 'snapshot_hash', 'status', 'byte_size', 'error', 'history', 'created_at', 'started_at', 'completed_at', 'expires_at', 'deleted_at'])]
class NovelExport extends Model
{
    /** 时间字段由生命周期服务明确维护。 */
    public $timestamps = false;

    /** 来源清单不可变，任务和旧审计不可删除。 */
    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if (array_diff(array_keys($record->getDirty()), ['status', 'byte_size', 'error', 'history', 'started_at', 'completed_at', 'expires_at', 'deleted_at']) !== []) {
                throw new LogicException('导出冻结清单不可覆盖');
            }
            $original = $record->getOriginal('history');
            if (array_slice($record->history, 0, count($original)) !== $original) {
                throw new LogicException('导出审计只能追加');
            }
        });
        static::deleting(fn () => throw new LogicException('导出任务审计不可删除'));
    }

    /** 固定编号、清单和时间类型。 */
    protected function casts(): array
    {
        return ['novel_id' => 'integer', 'actor_id' => 'integer', 'parent_id' => 'integer', 'end_chapter' => 'integer', 'byte_size' => 'integer',
            'snapshot' => 'array', 'history' => 'array', 'created_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime', 'deleted_at' => 'immutable_datetime'];
    }
}
