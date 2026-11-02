<?php

namespace Database\Factories;

use App\Models\Novel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 创建Novel的隔离测试数据。
 * @extends Factory<Novel>
 */
class NovelFactory extends Factory
{
    /** 返回基础字段，运行状态使用模型和数据库默认值。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(3),
            'genre' => '玄幻',
            'premise_raw' => '主人公在未知世界中寻找归途。',
            'target_chapters' => 10,
            'chapter_length_policy' => ['min_characters' => 2000, 'max_characters' => 3000],
        ];
    }
}
