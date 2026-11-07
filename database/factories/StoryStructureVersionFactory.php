<?php

namespace Database\Factories;

use App\Models\Novel;
use App\Models\StoryStructureVersion;
use App\Models\User;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 卷弧测试夹具，不投递生成请求。
 * @extends Factory<StoryStructureVersion>
 */
class StoryStructureVersionFactory extends Factory
{
    /** 默认空草稿不能直接作为冻结规划使用。 */
    public function definition(): array
    {
        return ['novel_id' => Novel::factory(), 'status' => 'draft', 'structure' => [], 'content_hash' => PlanningJson::hash([]), 'reason' => '隔离测试', 'actor_id' => User::factory(), 'created_at' => now()];
    }
}
