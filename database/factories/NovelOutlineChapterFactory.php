<?php

namespace Database\Factories;

use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Models\NovelOutlineChapter;
use App\Models\NovelOutlinePlan;
use App\Models\NovelStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 供唯一章序及候选展示测试使用。
 * @extends Factory<NovelOutlineChapter>
 */
class NovelOutlineChapterFactory extends Factory
{
    /** 数据只是候选规划，绝不写入正式 Chapter。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['outline_plan_id' => NovelOutlinePlan::factory(),
            'source_stage_id' => fn (array $a): int => NovelStage::factory()->create(['novel_id' => NovelOutlinePlan::findOrFail($a['outline_plan_id'])->novel_id, 'stage' => Stage::Outline, 'status' => StageStatus::Completed])->id,
            'chapter_number' => 1, 'title' => '封存档案中的旧航线', 'summary' => '主角找到旧案记录。', 'objective' => '确定旧案线索', 'conflict' => '档案记录与来信矛盾', 'hook' => '航线指向废港',
            'progression_points' => [['id' => 'chapter-1-point-1', 'change_type' => 'information', 'subject' => '林澈', 'before' => '不知道师父去向', 'action' => '核查旧航线', 'after' => '找到师父驶向废港的记录', 'verification' => '正文出现档案内容并改变调查目的地']]];
    }
}
