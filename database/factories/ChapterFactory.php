<?php

namespace Database\Factories;

use App\Models\Chapter;
use App\Models\Novel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 创建Chapter的隔离测试数据。
 * @extends Factory<Chapter>
 */
class ChapterFactory extends Factory
{
    /** 返回基础字段，运行状态使用模型和数据库默认值。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory(),
            'number' => 1,
            'title' => fake()->sentence(3),
            'objective' => '建立人物目标并推进冲突。',
        ];
    }
}
