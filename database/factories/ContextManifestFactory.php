<?php

namespace Database\Factories;

use App\Models\ContextManifest;
use App\Models\Novel;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 隔离清单夹具，不代表真实请求发送。
 * @extends Factory<ContextManifest>
 */
class ContextManifestFactory extends Factory
{
    /** 最小空清单只供权限及关系测试。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['novel_id' => Novel::factory(), 'chapter_number' => 1, 'purpose' => 'draft', 'status' => 'ready', 'document' => [], 'document_hash' => PlanningJson::hash([]), 'created_at' => now()];
    }
}
