<?php

namespace App\Models;

use Database\Factories\ChapterProgressionCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** 绑定正文版本和摘要的不可变推进检查证据。 */
#[Fillable(['chapter_id', 'version_id', 'source_stage_id', 'body_hash', 'verdict', 'result', 'created_at'])]
class ChapterProgressionCheck extends Model
{
    /** 隔离测试数据工厂。
     * @use HasFactory<ChapterProgressionCheckFactory>
     */
    use HasFactory;

    public $timestamps = false;

    /** 保护冻结来源和历史产物。 */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('成功保存的候选或检查不可覆盖'));
        static::deleting(fn () => throw new LogicException('首章历史不可删除'));
    }

    /** 保持结构化数据、计数和时间类型。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['result' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** 所属稳定章节节点。
     * @return BelongsTo<Chapter, $this>
     */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
