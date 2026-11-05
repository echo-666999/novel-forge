<?php

namespace Database\Factories;

use App\Models\CanonicalCommit;
use App\Models\StoryStateProjection;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 隔离关系夹具，不伪造业务验收通过。
 * @extends Factory<StoryStateProjection>
 */
class StoryStateProjectionFactory extends Factory
{
    /** 关联工厂仅提供约束测试数据，不替代真实服务验收。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['commit_id' => CanonicalCommit::factory(),
            'novel_id' => fn (array $a): int => CanonicalCommit::findOrFail($a['commit_id'])->novel_id,
            'state_version' => fn (array $a): int => CanonicalCommit::findOrFail($a['commit_id'])->state_version,
            'state' => ['entities' => []], 'state_hash' => PlanningJson::hash(['entities' => []]), 'created_at' => now()];
    }
}
