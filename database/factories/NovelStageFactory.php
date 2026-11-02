<?php

namespace Database\Factories;

use App\Enums\Stage;
use App\Models\Novel;
use App\Models\NovelStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 创建NovelStage的隔离测试数据。
 * @extends Factory<NovelStage>
 */
class NovelStageFactory extends Factory
{
    /** 返回基础字段，运行状态使用模型和数据库默认值。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory(),
            'stage' => Stage::Setting,
        ];
    }
}
