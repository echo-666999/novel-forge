<?php

namespace App\Models;

use Database\Factories\FormalRevisionChapterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** 正式稿修订的逐章记录：规则信号、模型建议、作者取舍、候选正文与事实等价结果。 */
#[Fillable(['formal_revision_id', 'chapter_number', 'source_commit_id', 'source_version_id', 'signals', 'suggestions', 'decisions', 'candidate_version_id', 'equivalence', 'status', 'error', 'cost_log_ids'])]
class FormalRevisionChapter extends Model
{
    /** @use HasFactory<FormalRevisionChapterFactory> */
    use HasFactory;

    /** 来源身份不可修改，记录不可删除。 */
    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('正式稿修订记录不可删除'));
        static::updating(function (FormalRevisionChapter $chapter): void {
            if ($chapter->isDirty(['formal_revision_id', 'chapter_number', 'source_commit_id', 'source_version_id'])) {
                throw new LogicException('修订来源不可修改');
            }
        });
    }

    /** 编号与结构化记录转换。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['formal_revision_id' => 'integer', 'chapter_number' => 'integer', 'source_commit_id' => 'integer', 'source_version_id' => 'integer',
            'signals' => 'array', 'suggestions' => 'array', 'decisions' => 'array', 'candidate_version_id' => 'integer', 'equivalence' => 'array', 'cost_log_ids' => 'array'];
    }

    /** 所属修订任务。
     * @return BelongsTo<FormalRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(FormalRevision::class, 'formal_revision_id');
    }

    /** 作者可读的状态名称。 */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => '待生成建议', 'suggested' => '待取舍', 'decided' => '待核对', 'unchanged' => '无改动', 'verified' => '已核对',
            'failed' => '未通过', 'committed' => '已进入候选基线', default => $status,
        };
    }
}
