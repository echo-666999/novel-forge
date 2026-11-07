<?php

namespace App\Models;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use Database\Factories\NovelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 小说及其运行计数骨架，正式剧情由后续提交体系维护。 */
#[Fillable(['title', 'genre', 'premise_raw', 'target_chapters', 'chapter_length_policy', 'status', 'current_stage', 'execution_count', 'max_execution_count', 'progress', 'chapters_done', 'chapters_total', 'state_version', 'generation_mode', 'generation_config', 'pause_requested', 'run_control_history'])]
class Novel extends Model
{
    /** 使用隔离测试工厂创建关联数据。
     * @use HasFactory<NovelFactory>
     */
    use HasFactory;

    /** 未刷新数据库前也可读取一致的初始运行状态。
     * @var array<string, mixed>
     */
    protected $attributes = [
        'generation_mode' => 'simulation',
        'status' => 'pending',
        'execution_count' => 0,
        'max_execution_count' => 5,
        'progress' => '0.00',
        'chapters_done' => 0,
        'chapters_total' => 0,
        'state_version' => 0,
    ];

    /** 定义枚举、结构化快照和不可变时间的转换。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'immutable_datetime',
            'archive_history' => 'array',
            'generation_mode' => GenerationMode::class,
            'generation_config' => 'array',
            // 运行控制独立于冻结生成配置，避免暂停使来源摘要失效。
            'pause_requested' => 'boolean',
            'run_control_history' => 'array',
            'status' => NovelStatus::class,
            'current_stage' => Stage::class,
            'chapter_length_policy' => 'array',
            'target_chapters' => 'integer',
            'execution_count' => 'integer',
            'max_execution_count' => 'integer',
            'progress' => 'decimal:2',
            'chapters_done' => 'integer',
            'chapters_total' => 'integer',
            'state_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** 阶段执行记录，允许同一阶段产生多条记录。
     * @return HasMany<NovelStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(NovelStage::class);
    }

    /** 小说的稳定章节节点，不代表正式正文版本。
     * @return HasMany<Chapter, $this>
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class);
    }

    /** 作者确认的设定版本。
     * @return HasMany<NovelSettingConfirmation, $this>
     */
    public function settingConfirmations(): HasMany
    {
        return $this->hasMany(NovelSettingConfirmation::class);
    }

    /** 按确认版本保留的大纲计划。
     * @return HasMany<NovelOutlinePlan, $this>
     */
    public function outlinePlans(): HasMany
    {
        return $this->hasMany(NovelOutlinePlan::class);
    }
}
