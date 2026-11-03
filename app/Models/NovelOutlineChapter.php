<?php

namespace App\Models;

use Database\Factories\NovelOutlineChapterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** 整批通过契约后接纳的章节规划；不会创建正式正文或事件。 */
#[Fillable(['outline_plan_id', 'source_stage_id', 'chapter_number', 'title', 'summary', 'objective', 'conflict', 'hook', 'progression_points'])]
class NovelOutlineChapter extends Model
{
    /** @use HasFactory<NovelOutlineChapterFactory> */
    use HasFactory;

    public $timestamps = false;

    /** 成功章节不原地修改或删除，后续修订应创建独立计划版本。 */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('已接纳的大纲章节不可覆盖'));
        static::deleting(fn () => throw new LogicException('已接纳的大纲章节不可删除'));
    }

    /** 保留章序与推进点结构。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['chapter_number' => 'integer', 'progression_points' => 'array'];
    }

    /** 所属候选计划。
     * @return BelongsTo<NovelOutlinePlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(NovelOutlinePlan::class, 'outline_plan_id');
    }
}
