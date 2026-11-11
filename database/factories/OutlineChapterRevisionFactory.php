<?php

namespace Database\Factories;

use App\Models\NovelOutlineChapter;
use App\Models\OutlineChapterRevision;
use App\Models\User;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 章纲修订测试数据，默认改写原章纲的钩子并保留推进点编号。
 * @extends Factory<OutlineChapterRevision>
 */
class OutlineChapterRevisionFactory extends Factory
{
    /** 数据只是修订规划，不写入正式正文。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['outline_chapter_id' => NovelOutlineChapter::factory(),
            'novel_id' => fn (array $a): int => NovelOutlineChapter::findOrFail($a['outline_chapter_id'])->plan->novel_id,
            'parent_id' => null,
            'summary' => fn (array $a): string => NovelOutlineChapter::findOrFail($a['outline_chapter_id'])->summary,
            'objective' => fn (array $a): string => NovelOutlineChapter::findOrFail($a['outline_chapter_id'])->objective,
            'conflict' => fn (array $a): string => NovelOutlineChapter::findOrFail($a['outline_chapter_id'])->conflict,
            'hook' => '修订后的章末钩子',
            'progression_points' => fn (array $a): array => NovelOutlineChapter::findOrFail($a['outline_chapter_id'])->progression_points,
            'content_hash' => fn (array $a): string => PlanningJson::hash(['summary' => $a['summary'], 'objective' => $a['objective'], 'conflict' => $a['conflict'], 'hook' => $a['hook'], 'progression_points' => $a['progression_points']]),
            'reason' => '章纲与前文正式正文冲突', 'actor_id' => User::factory(), 'created_at' => now()];
    }
}
