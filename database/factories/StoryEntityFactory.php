<?php

namespace Database\Factories;

use App\Models\Novel;
use App\Models\StoryEntity;
use App\Models\User;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 隔离关系夹具，不伪造业务验收通过。
 * @extends Factory<StoryEntity>
 */
class StoryEntityFactory extends Factory
{
    /** 由测试明确覆盖业务数据；空定义不代表允许生产直接创建。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $definition = ['name' => '隔离实体', 'type' => 'character', 'aliases' => [], 'description' => '隔离测试'];

        return ['novel_id' => Novel::factory(), 'name' => '隔离实体', 'normalized_name' => '隔离实体', 'type' => 'character', 'aliases' => [], 'definition' => $definition,
            'definition_hash' => PlanningJson::hash($definition), 'origin' => ['fixture' => true], 'approved_by' => User::factory(), 'approved_at' => now()];
    }
}
