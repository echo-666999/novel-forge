<?php

namespace Database\Factories;

use App\Enums\Stage;
use App\Models\ChapterProgressionCheck;
use App\Models\ChapterVersion;
use App\Models\NovelStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 绑定同章正文的检查记录，仅供隔离测试。
 * @extends Factory<ChapterProgressionCheck>
 */
class ChapterProgressionCheckFactory extends Factory
{
    /** 检查来源和摘要均由对应正文派生。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['version_id' => ChapterVersion::factory(),
            'chapter_id' => fn (array $a): int => ChapterVersion::findOrFail($a['version_id'])->chapter_id,
            'source_stage_id' => fn (array $a): int => NovelStage::factory()->create(['novel_id' => ChapterVersion::findOrFail($a['version_id'])->chapter->novel_id, 'stage' => Stage::Content])->id,
            'body_hash' => fn (array $a): string => ChapterVersion::findOrFail($a['version_id'])->body_hash,
            'verdict' => 'insufficient', 'result' => ['fixture' => true, 'points' => []], 'created_at' => now()];
    }
}
