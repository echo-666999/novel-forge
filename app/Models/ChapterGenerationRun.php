<?php

namespace App\Models;

use Database\Factories\ChapterGenerationRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** 首章运行及独立请求额度，输入冻结后不能原地更换。 */
#[Fillable(['chapter_id', 'outline_chapter_id', 'parent_run_id', 'source_version_id', 'length_repair_count', 'snapshot', 'config', 'input_hash', 'plan_data', 'fine_stage_id', 'status', 'next_step', 'invocation_id', 'requests_used', 'request_limit', 'retry_count', 'stop_reason', 'control_history'])]
class ChapterGenerationRun extends Model
{
    /** 隔离测试数据工厂。
     * @use HasFactory<ChapterGenerationRunFactory>
     */
    use HasFactory;

    /** 保护冻结来源和历史产物。 */
    protected static function booted(): void
    {
        static::updating(function (self $run): void {
            if ($run->isDirty(['chapter_id', 'outline_chapter_id', 'parent_run_id', 'source_version_id', 'snapshot', 'config', 'input_hash']) || ($run->getRawOriginal('plan_data') !== null && $run->isDirty(['plan_data', 'fine_stage_id']))) {
                throw new LogicException('已冻结的首章输入和细纲不能覆盖');
            }
        });
        static::deleting(fn () => throw new LogicException('首章历史不可删除'));
    }

    /** 保持结构化数据、计数和时间类型。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'config' => 'array', 'plan_data' => 'array', 'control_history' => 'array', 'requests_used' => 'integer', 'request_limit' => 'integer', 'retry_count' => 'integer', 'length_repair_count' => 'integer', 'parent_run_id' => 'integer', 'source_version_id' => 'integer'];
    }

    /** 所属稳定章节节点。
     * @return BelongsTo<Chapter, $this>
     */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
