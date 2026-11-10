<?php

namespace App\Models;

use Database\Factories\ChapterVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** 不可变正文候选，检查失败或修订不能覆盖历史。 */
#[Fillable(['chapter_id', 'run_id', 'source_stage_id', 'parent_version_id', 'version', 'title', 'body', 'body_hash', 'character_count', 'count_version', 'created_at'])]
class ChapterVersion extends Model
{
    /** 隔离测试数据工厂。
     * @use HasFactory<ChapterVersionFactory>
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
        return ['version' => 'integer', 'character_count' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** 展示章内真实版本号，保留全局记录编号供审计定位。 */
    public function displayVersion(): string
    {
        return 'V'.$this->version.'（记录 #'.$this->id.'）';
    }

    /** 所属稳定章节节点。
     * @return BelongsTo<Chapter, $this>
     */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
