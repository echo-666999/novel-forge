<?php

namespace Database\Factories;

use App\Models\Chapter;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** 隔离关系夹具，不伪造业务验收通过。
 * @extends Factory<ChapterSubmissionRun>
 */
class ChapterSubmissionRunFactory extends Factory
{
    /** 由测试明确覆盖业务数据；空定义不代表允许生产直接创建。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['version_id' => ChapterVersion::factory(),
            'chapter_id' => fn (array $a): int => ChapterVersion::findOrFail($a['version_id'])->chapter_id,
            'novel_id' => fn (array $a): int => Chapter::findOrFail($a['chapter_id'])->novel_id,
            'body_hash' => fn (array $a): string => ChapterVersion::findOrFail($a['version_id'])->body_hash,
            'expected_state_version' => 0, 'snapshot' => ['fixture' => true], 'config' => ['fixture' => true], 'input_hash' => str_repeat('0', 64),
            'status' => 'pending', 'step' => 'review', 'invocation_id' => (string) Str::uuid7(), 'requests_used' => 0, 'request_limit' => 2, 'control_history' => []];
    }
}
