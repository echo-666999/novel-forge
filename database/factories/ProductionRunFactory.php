<?php

namespace Database\Factories;

use App\Models\ProductionRun;
use App\Models\Novel;
use App\Models\User;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** 隔离测试的自动授权，不触发队列或供应商。
 * @extends Factory<ProductionRun>
 */
class ProductionRunFactory extends Factory
{
    /** 构造独立小说授权，生产代码必须使用显式授权入口。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['novel_id' => Novel::factory(), 'author_id' => User::factory(), 'policy' => ProductionRun::policy(),
            'policy_hash' => PlanningJson::hash(ProductionRun::policy()), 'commit_key' => (string) Str::uuid7(), 'status' => 'pending', 'step' => 'setting', 'artifacts' => [], 'history' => []];
    }
}
