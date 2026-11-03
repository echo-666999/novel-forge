<?php

namespace Database\Factories;

use App\Models\NovelOutlinePlan;
use App\Models\NovelSettingConfirmation;
use App\Services\Ai\TextRequestOptions;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** 供计划关联与只读页面测试使用，不自动投递任务。
 * @extends Factory<NovelOutlinePlan>
 */
class NovelOutlinePlanFactory extends Factory
{
    /** 创建关联一致的冻结计划夹具。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $config = TextRequestOptions::outline(0.7, 1, 10, 10)->toArray();
        unset($config['range']);

        return ['setting_confirmation_id' => NovelSettingConfirmation::factory(),
            'novel_id' => fn (array $a): int => NovelSettingConfirmation::findOrFail($a['setting_confirmation_id'])->novel_id,
            'target_chapters' => 10,
            'snapshot' => function (array $a): array {
                $confirmation = NovelSettingConfirmation::findOrFail($a['setting_confirmation_id']);

                return ['confirmation_hash' => $confirmation->content_hash, 'novel' => $confirmation->novel->only(['id', 'title', 'genre', 'premise_raw', 'target_chapters', 'chapter_length_policy']), 'confirmed_setting' => $confirmation->setting, 'decisions' => $confirmation->decisions];
            },
            'config' => [...$config, 'model' => 'gpt-4.1-mini', 'batch_size' => 10, 'prompt_version' => 'b03-outline-prompt-v1', 'enable_fine_outline' => true],
            'next_chapter' => 1, 'status' => 'pending', 'invocation_id' => (string) Str::uuid7(), 'control_history' => [],
        ];
    }
}
