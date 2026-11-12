<?php

namespace App\Models;

use Database\Factories\FormalRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** 正式稿修订任务：在候选基线中只改文字、事实不变，验证后由作者切换。 */
#[Fillable(['novel_id', 'baseline_id', 'from_chapter', 'to_chapter', 'intent', 'status', 'model', 'request_limit', 'requests_used', 'created_by'])]
class FormalRevision extends Model
{
    /** @use HasFactory<FormalRevisionFactory> */
    use HasFactory;

    /** 修订记录是审计历史，不能删除。 */
    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('正式稿修订记录不可删除'));
    }

    /** 编号与计数转换。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['novel_id' => 'integer', 'baseline_id' => 'integer', 'from_chapter' => 'integer', 'to_chapter' => 'integer',
            'request_limit' => 'integer', 'requests_used' => 'integer', 'created_by' => 'integer'];
    }

    /** 所属小说。
     * @return BelongsTo<Novel, $this>
     */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** 候选基线。
     * @return BelongsTo<CanonicalBaseline, $this>
     */
    public function baseline(): BelongsTo
    {
        return $this->belongsTo(CanonicalBaseline::class);
    }

    /** 逐章记录。
     * @return HasMany<FormalRevisionChapter, $this>
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(FormalRevisionChapter::class)->orderBy('chapter_number');
    }

    /** 作者可读的状态名称。 */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'open' => '进行中', 'ready' => '候选已验证', 'switched' => '已切换', 'abandoned' => '已放弃', default => $status,
        };
    }
}
