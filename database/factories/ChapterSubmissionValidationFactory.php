<?php

namespace Database\Factories;

use App\Models\ChapterEventExtraction;
use App\Models\ChapterReview;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterSubmissionValidation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 隔离关系夹具，不伪造业务验收通过。
 * @extends Factory<ChapterSubmissionValidation>
 */
class ChapterSubmissionValidationFactory extends Factory
{
    /** 关联工厂仅提供约束测试数据，不替代真实服务验收。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['submission_run_id' => ChapterSubmissionRun::factory(),
            'review_id' => fn (array $a): int => ChapterReview::factory()->create(['submission_run_id' => $a['submission_run_id']])->id,
            'extraction_id' => fn (array $a): int => ChapterEventExtraction::factory()->create(['submission_run_id' => $a['submission_run_id']])->id,
            'verdict' => 'blocked', 'input_hash' => str_repeat('0', 64), 'result' => ['fixture' => true], 'created_at' => now()];
    }
}
