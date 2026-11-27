<?php

namespace Database\Factories;

use App\Models\StoryPreset;
use Illuminate\Database\Eloquent\Factories\Factory;

/** 隔离测试用的自定义文风预设；题材包用 genre 状态。
 * @extends Factory<StoryPreset>
 */
class StoryPresetFactory extends Factory
{
    /** 默认是一条自定义文风预设。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['kind' => 'style', 'key' => 'custom_'.fake()->unique()->lexify('??????'), 'name' => '隔离文风', 'description' => '测试用',
            'content' => ['narrator_attitude' => '冷面吐槽', 'sentence_rhythm' => '短句', 'dialogue_share' => '40%–50%', 'humor_level' => 'mid', 'humor_devices' => ['反差'],
                'emotion_expression' => '外放', 'vocabulary' => '口语', 'banned_words' => [], 'samples' => ['隔离示范段落。'], 'draft_fragment' => ''],
            'is_builtin' => false, 'version' => 1];
    }

    /** 自定义题材包。 */
    public function genre(): static
    {
        return $this->state(fn (): array => ['kind' => 'genre', 'name' => '隔离题材', 'content' => ['core_promise' => '隔离承诺', 'payoff_types' => [['name' => '打脸', 'weight' => 100]],
            'beat_template' => '受压 → 反击', 'world_rules_hints' => '', 'archetypes' => [], 'taboos' => ['主角圣母'], 'default_style' => '']]);
    }
}
