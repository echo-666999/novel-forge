<?php

namespace App\Models;

use Database\Factories\NovelOutlinePlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** 一个确认版本对应一个有游标的大纲计划，失败批次不覆盖成功章节。 */
#[Fillable(['novel_id', 'setting_confirmation_id', 'target_chapters', 'snapshot', 'config', 'roadmap', 'handoff', 'next_chapter', 'status', 'invocation_id', 'stop_reason', 'control_history'])]
class NovelOutlinePlan extends Model
{
    /** @use HasFactory<NovelOutlinePlanFactory> */
    use HasFactory;

    /** 启动后冻结输入与模型配置，继续操作只能更新游标、令牌和运行状态。 */
    protected static function booted(): void
    {
        static::updating(function (self $plan): void {
            if ($plan->isDirty(['novel_id', 'setting_confirmation_id', 'target_chapters', 'snapshot', 'config'])) {
                throw new LogicException('大纲冻结输入与配置不可修改');
            }
        });
    }

    /** 统一 JSONB、章序及时间转换。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'config' => 'array', 'roadmap' => 'array', 'handoff' => 'array', 'control_history' => 'array', 'target_chapters' => 'integer', 'next_chapter' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** 所属小说。
     * @return BelongsTo<Novel, $this>
     */
    public function novel(): BelongsTo
    {
        return $this->belongsTo(Novel::class);
    }

    /** 已确认的来源设定。
     * @return BelongsTo<NovelSettingConfirmation, $this>
     */
    public function confirmation(): BelongsTo
    {
        return $this->belongsTo(NovelSettingConfirmation::class, 'setting_confirmation_id');
    }

    /** 已校验的分章候选，不等同于正式章节。
     * @return HasMany<NovelOutlineChapter, $this>
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(NovelOutlineChapter::class, 'outline_plan_id');
    }

    /** 计划状态的中文投影，费用和失败原因另行展示。 */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => '待处理', 'running' => '生成中', 'paused' => '已暂停', 'error' => '执行失败', 'completed' => '大纲候选已完成', default => '未知状态',
        };
    }
}
