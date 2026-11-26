<?php

namespace App\Models;

use App\Models\Concerns\PreservesJsonFloats;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** 不可变的已揭示事实补录：绑定原始正式提交，台账重放时读取。 */
#[Fillable(['novel_id', 'commit_id', 'chapter_number', 'entries', 'entries_hash', 'cost_log_id', 'created_by', 'created_at'])]
class OpenItemBackfill extends Model
{
    use PreservesJsonFloats;

    public $timestamps = false;

    /** 补录结果与正式提交一样不可覆盖或删除。 */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('补录产物不可覆盖'));
        static::deleting(fn () => throw new LogicException('补录产物不可删除'));
    }

    /** 保留结构化结果、整数和不可变时间类型。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['novel_id' => 'integer', 'commit_id' => 'integer', 'chapter_number' => 'integer', 'entries' => 'array', 'cost_log_id' => 'integer', 'created_by' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
