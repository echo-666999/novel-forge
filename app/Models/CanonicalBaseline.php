<?php

namespace App\Models;

use Database\Factories\CanonicalBaselineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** 小说正式基线；起始章之前的提交沿父基线解析，同书只有一个活动基线，旧基线只读保留。 */
#[Fillable(['novel_id', 'parent_id', 'fork_chapter', 'status', 'purpose', 'base_head_state', 'verification', 'reason', 'created_by', 'activated_by', 'activated_at'])]
class CanonicalBaseline extends Model
{
    /** @use HasFactory<CanonicalBaselineFactory> */
    use HasFactory;

    /** 基线是正式历史的一部分，不能删除。 */
    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('正式基线不可删除'));
        static::updating(function (CanonicalBaseline $baseline): void {
            if ($baseline->isDirty(['novel_id', 'parent_id', 'fork_chapter', 'purpose', 'base_head_state', 'reason', 'created_by'])) {
                throw new LogicException('基线身份不可修改');
            }
        });
    }

    /** 编号、序号与验证结果转换。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['novel_id' => 'integer', 'parent_id' => 'integer', 'fork_chapter' => 'integer', 'base_head_state' => 'integer',
            'verification' => 'array', 'created_by' => 'integer', 'activated_by' => 'integer', 'activated_at' => 'immutable_datetime'];
    }

    /** 所属小说。
     * @return BelongsTo<Novel, $this>
     */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** 父基线。
     * @return BelongsTo<CanonicalBaseline, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** 本基线自有的提交。
     * @return HasMany<CanonicalCommit, $this>
     */
    public function commits(): HasMany
    {
        return $this->hasMany(CanonicalCommit::class, 'baseline_id');
    }

    /** 作者可读的状态名称。 */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => '活动', 'candidate' => '候选', 'verified' => '已验证', 'superseded' => '已被替换', 'abandoned' => '已放弃', default => $status,
        };
    }
}
