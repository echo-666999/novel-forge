<?php

namespace Database\Factories;

use App\Models\CanonicalBaseline;
use App\Models\Novel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 正式基线测试数据，默认是一本书的初始活动基线。
 * @extends Factory<CanonicalBaseline>
 */
class CanonicalBaselineFactory extends Factory
{
    /** 初始活动基线从第1章开始。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['novel_id' => Novel::factory(), 'parent_id' => null, 'fork_chapter' => 1, 'status' => 'active', 'purpose' => 'initial',
            'base_head_state' => 0, 'verification' => null, 'reason' => '测试初始基线', 'created_by' => null, 'activated_by' => null, 'activated_at' => now()];
    }
}
