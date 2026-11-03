<?php

namespace Database\Factories;

use App\Models\CostLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 提供无模型调用、无真实费用的独立测试数据。
 * @extends Factory<CostLog>
 */
class CostLogFactory extends Factory
{
    /** 默认只创建模拟记录，防止工厂被误认为真实账单。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'request_uuid' => fake()->uuid(), 'mode' => 'fake', 'requested_provider' => 'fake', 'requested_model' => 'a03-fake-v1',
            'route_version' => 'a03-fake-v1', 'prompt_hash' => hash('sha256', 'A03 test'), 'request_parameters' => [],
            'status' => 'pending', 'billing_status' => 'simulated',
        ];
    }
}
