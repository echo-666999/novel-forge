<?php

namespace Database\Factories;

use App\Models\ChapterReview;
use App\Models\ChapterSubmissionRun;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 隔离关系夹具，不伪造业务验收通过。
 * @extends Factory<ChapterReview>
 */
class ChapterReviewFactory extends Factory
{
    /** 由测试明确覆盖业务数据；空定义不代表允许生产直接创建。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['submission_run_id' => ChapterSubmissionRun::factory(),
            'novel_id' => fn (array $a): int => ChapterSubmissionRun::findOrFail($a['submission_run_id'])->novel_id,
            'chapter_id' => fn (array $a): int => ChapterSubmissionRun::findOrFail($a['submission_run_id'])->chapter_id,
            'version_id' => fn (array $a): int => ChapterSubmissionRun::findOrFail($a['submission_run_id'])->version_id,
            'body_hash' => fn (array $a): string => ChapterSubmissionRun::findOrFail($a['submission_run_id'])->body_hash,
            'verdict' => 'needs_revision', 'result' => [], 'result_hash' => PlanningJson::hash([]), 'created_at' => now()];
    }
}
