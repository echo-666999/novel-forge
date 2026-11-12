<?php

namespace Database\Factories;

use App\Models\CanonicalBaseline;
use App\Models\FormalRevision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 正式稿修订任务测试数据，默认挂在一个候选基线上。
 * @extends Factory<FormalRevision>
 */
class FormalRevisionFactory extends Factory
{
    /** 默认修订第1章。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['baseline_id' => CanonicalBaseline::factory()->state(['status' => 'candidate', 'purpose' => 'formal_revision']),
            'novel_id' => fn (array $a): int => CanonicalBaseline::findOrFail($a['baseline_id'])->novel_id,
            'from_chapter' => 1, 'to_chapter' => 1, 'intent' => '去掉重复表达', 'status' => 'open', 'model' => 'gpt-6.1-terra',
            'request_limit' => 4, 'requests_used' => 0, 'created_by' => User::factory()];
    }
}
