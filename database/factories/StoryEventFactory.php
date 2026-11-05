<?php

namespace Database\Factories;

use App\Models\CanonicalCommit;
use App\Models\StoryEntity;
use App\Models\StoryEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 隔离关系夹具，不伪造业务验收通过。
 * @extends Factory<StoryEvent>
 */
class StoryEventFactory extends Factory
{
    /** 关联工厂仅提供约束测试数据，不替代真实服务验收。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['commit_id' => CanonicalCommit::factory(),
            'novel_id' => fn (array $a): int => CanonicalCommit::findOrFail($a['commit_id'])->novel_id,
            'chapter_id' => fn (array $a): int => CanonicalCommit::findOrFail($a['commit_id'])->chapter_id,
            'version_id' => fn (array $a): int => CanonicalCommit::findOrFail($a['commit_id'])->version_id,
            'actor_entity_id' => fn (array $a): int => StoryEntity::factory()->create(['novel_id' => $a['novel_id']])->id,
            'event_key' => 'event-1', 'narrative_order' => 1, 'event_type' => 'observation', 'fact_category' => 'objective_confirmed',
            'payload' => ['fixture' => true], 'evidence' => [], 'created_at' => now()];
    }
}
