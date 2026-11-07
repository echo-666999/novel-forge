<?php

namespace Database\Factories;

use App\Models\Novel;
use App\Models\RequirementVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** 隔离的要求版本测试夹具，不生成小说正文。
 * @extends Factory<RequirementVersion>
 */
class RequirementVersionFactory extends Factory
{
    /** 原句及摘要来自同一小说，保持可追溯。 */
    public function definition(): array
    {
        return ['novel_id' => Novel::factory(), 'requirement_key' => (string) Str::uuid7(), 'revision' => 1,
            'source_quote' => fn (array $data): string => Novel::findOrFail($data['novel_id'])->premise_raw,
            'source_hash' => fn (array $data): string => hash('sha256', Novel::findOrFail($data['novel_id'])->premise_raw),
            'description' => '保留创意要求', 'category' => '剧情', 'strength' => 'hard', 'status' => 'unresolved',
            'reason' => '隔离测试', 'actor_id' => User::factory(), 'created_at' => now()];
    }
}
