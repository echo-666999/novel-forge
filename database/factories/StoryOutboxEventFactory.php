<?php

namespace Database\Factories;

use App\Models\CanonicalCommit;
use App\Models\StoryOutboxEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 隔离关系夹具，不伪造业务验收通过。
 * @extends Factory<StoryOutboxEvent>
 */
class StoryOutboxEventFactory extends Factory
{
    /** 关联工厂仅提供约束测试数据，不替代真实服务验收。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['commit_id' => CanonicalCommit::factory(), 'event_type' => 'chapter.committed', 'payload' => ['fixture' => true], 'created_at' => now()];
    }
}
