<?php

namespace Database\Factories;

use App\Models\ModelPrice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 隔离测试用的单价，不代表真实供应商价格。
 * @extends Factory<ModelPrice>
 */
class ModelPriceFactory extends Factory
{
    /** 默认人民币，每百万输入1元、输出2元。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['model' => 'gpt-6.1-terra', 'currency' => 'CNY', 'input_per_million' => '1.00000000', 'output_per_million' => '2.00000000',
            'source' => '隔离测试单价', 'verified_on' => now()->toDateString(), 'effective_at' => now()->subMinute(), 'created_by' => User::factory(), 'created_at' => now()];
    }
}
