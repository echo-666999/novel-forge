<?php

namespace App\Models;

use Database\Factories\OutlineChapterRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** 未提交章节的章纲修订版本；只在之后冻结的新运行中生效，旧运行与原大纲不变。 */
#[Fillable(['novel_id', 'outline_chapter_id', 'parent_id', 'summary', 'objective', 'conflict', 'hook', 'progression_points', 'content_hash', 'reason', 'actor_id', 'created_at'])]
class OutlineChapterRevision extends Model
{
    /** @use HasFactory<OutlineChapterRevisionFactory> */
    use HasFactory;

    /** 修订历史只保存创建时间。 */
    public $timestamps = false;

    /** 修订只能追加新版本。 */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('章纲修订历史不可覆盖'));
        static::deleting(fn () => throw new LogicException('章纲修订历史不可删除'));
    }

    /** 编号与推进点结构转换。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['novel_id' => 'integer', 'outline_chapter_id' => 'integer', 'parent_id' => 'integer', 'actor_id' => 'integer',
            'progression_points' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** 被修订的原大纲章节。
     * @return BelongsTo<NovelOutlineChapter, $this>
     */
    public function outlineChapter(): BelongsTo
    {
        return $this->belongsTo(NovelOutlineChapter::class, 'outline_chapter_id');
    }

    /** 操作账号。
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
