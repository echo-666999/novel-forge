<?php

namespace Database\Factories;

use App\Enums\Stage;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterVersion;
use App\Models\NovelStage;
use App\Services\Pipeline\ContentSchema;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 正文约束测试夹具，永不写入开发样本。
 * @extends Factory<ChapterVersion>
 */
class ChapterVersionFactory extends Factory
{
    /** 从同运行派生章节及阶段，正文摘要与计数保持一致。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $body = '林澈对照原档，确认航线被人篡改。';

        return ['run_id' => ChapterGenerationRun::factory(),
            'chapter_id' => fn (array $a): int => ChapterGenerationRun::findOrFail($a['run_id'])->chapter_id,
            'source_stage_id' => fn (array $a): int => NovelStage::factory()->create(['novel_id' => ChapterGenerationRun::findOrFail($a['run_id'])->chapter->novel_id, 'stage' => Stage::Content])->id,
            'version' => fn (array $a): int => (int) ChapterVersion::where('chapter_id', $a['chapter_id'])->max('version') + 1,
            'title' => '航线上的墨痕', 'body' => $body, 'body_hash' => hash('sha256', $body), 'character_count' => ContentSchema::count($body), 'count_version' => ContentSchema::COUNT_VERSION, 'created_at' => now()];
    }
}
