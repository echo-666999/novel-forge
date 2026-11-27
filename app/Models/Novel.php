<?php

namespace App\Models;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\ReviewStrictness;
use App\Enums\Stage;
use App\Models\Concerns\PreservesJsonFloats;
use Database\Factories\NovelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 小说及其运行计数骨架，正式剧情由后续提交体系维护。 */
#[Fillable(['title', 'genre', 'premise_raw', 'target_chapters', 'chapter_length_policy', 'status', 'current_stage', 'execution_count', 'max_execution_count', 'progress', 'chapters_done', 'chapters_total', 'state_version', 'generation_mode', 'generation_config', 'pause_requested', 'run_control_history', 'creative_brief', 'budget_amount', 'budget_currency', 'budget_history', 'active_baseline_id', 'review_strictness', 'story_profile'])]
class Novel extends Model
{
    /** 使用隔离测试工厂创建关联数据。
     * @use HasFactory<NovelFactory>
     */
    use HasFactory;

    use PreservesJsonFloats;

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
        'review_strictness' => 'loose',
    ];

    /** 定义枚举、结构化快照和不可变时间的转换。
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'immutable_datetime',
            'archive_history' => 'array',
            // 补充创作要求与预算；预算金额以十进制字符串读取，不经过浮点数。
            'creative_brief' => 'array',
            'story_profile' => 'array',
            'budget_amount' => 'decimal:8',
            'budget_history' => 'array',
            'generation_mode' => GenerationMode::class,
            'review_strictness' => ReviewStrictness::class,
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
            'active_baseline_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** 冻结进设定、大纲和正文运行的作者输入；补充创作要求、题材与文风只在填写时才带上，旧书快照保持不变。
     * @return array<string, mixed>
     */
    public function planningInput(): array
    {
        $input = $this->only(['id', 'title', 'genre', 'premise_raw', 'target_chapters', 'chapter_length_policy']);
        if (filled($this->creative_brief)) {
            $input['creative_brief'] = $this->creative_brief;
        }
        // 建书时选了题材包或文风预设的书，冻结整份副本；后台之后修改列表不影响本书。
        if (filled($this->story_profile)) {
            $input['story_profile'] = $this->story_profile;
        }

        return $input;
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
