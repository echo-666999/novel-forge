<?php

namespace Database\Factories;

use App\Models\Chapter;
use App\Models\ChapterGenerationRun;
use App\Models\NovelOutlineChapter;
use App\Services\Pipeline\ContentSchema;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** 仅用于隔离模型与约束测试，不注册业务播种器或投递模型请求。
 * @extends Factory<ChapterGenerationRun>
 */
class ChapterGenerationRunFactory extends Factory
{
    /** 创建数据库关联一致的合成运行；生产启动必须经过应用服务。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['outline_chapter_id' => NovelOutlineChapter::factory(),
            'chapter_id' => fn (array $a): int => Chapter::factory()->create(['novel_id' => NovelOutlineChapter::findOrFail($a['outline_chapter_id'])->plan->novel_id])->id,
            'snapshot' => ['fixture' => true], 'config' => ['version' => ContentSchema::VERSION, 'fixture' => true],
            'input_hash' => PlanningJson::hash([['fixture' => true], ['version' => ContentSchema::VERSION, 'fixture' => true]]),
            'status' => 'pending', 'next_step' => 'draft', 'invocation_id' => (string) Str::uuid7(),
            'requests_used' => 0, 'request_limit' => 3, 'retry_count' => 0, 'control_history' => []];
    }
}
