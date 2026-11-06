<?php

namespace Database\Factories;

use App\Models\Foreshadow;
use App\Models\Novel;
use App\Models\User;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** 仅用于隔离账本测试，不自动向开发库填充伏笔。
 * @extends Factory<Foreshadow>
 */
class ForeshadowFactory extends Factory
{
    /** 默认未埋设计划，正式证据由测试明确创建。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['novel_id' => Novel::factory(), 'title' => '待核实的旧信', 'description' => '信中地址须在后续得到解释', 'plant_chapter' => 1, 'target_chapter' => 5, 'status' => 'planned', 'revision' => 0,
            'request_key' => (string) Str::uuid7(), 'request_hash' => PlanningJson::hash(['fixture' => true]), 'created_by' => User::factory()];
    }
}
