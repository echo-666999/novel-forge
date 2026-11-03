<?php

namespace Database\Factories;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Models\Novel;
use App\Models\NovelSettingConfirmation;
use App\Models\NovelStage;
use App\Models\User;
use App\Services\Pipeline\ConfirmSettingService;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** 仅供隔离测试的已确认夹具，不调用模型或代替真实作者确认。
 * @extends Factory<NovelSettingConfirmation>
 */
class NovelSettingConfirmationFactory extends Factory
{
    /** 创建具有一致来源与摘要的确认版本。
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'novel_id' => Novel::factory()->state(['generation_mode' => GenerationMode::SettingReal, 'status' => NovelStatus::Paused, 'current_stage' => Stage::Outline, 'execution_count' => 1, 'generation_config' => ['invocation_id' => (string) Str::uuid7(), 'enable_fine_outline' => true]]),
            'source_stage_id' => fn (array $a): int => NovelStage::factory()->create(['novel_id' => $a['novel_id'], 'stage' => Stage::Setting, 'status' => StageStatus::Completed, 'execution_count' => 1, 'input' => ['simulation' => false], 'output' => ['simulation' => false, 'stage' => 'setting', 'data' => ['setting' => self::setting(), 'fixture' => true]]])->id,
            'source_hash' => fn (array $a): string => (new ConfirmSettingService)->sourceHash(NovelStage::findOrFail($a['source_stage_id'])),
            'setting' => self::setting(), 'decisions' => [],
            'content_hash' => fn (array $a): string => PlanningJson::hash(['source_hash' => $a['source_hash'], 'setting' => (is_string($a['setting']) ? json_decode($a['setting'], true) : $a['setting']), 'decisions' => (is_string($a['decisions']) ? json_decode($a['decisions'], true) : $a['decisions'])]),
            'confirmed_by' => User::factory(), 'confirmed_at' => now(),
        ];
    }

    /** 合成设定仅供测试输入，不冒充任何真实模型输出或正式剧情。
     * @return array<string, mixed>
     */
    public static function setting(): array
    {
        return ['world' => ['era' => '架空近代', 'background' => '守灯人调查失踪旧案。', 'rules' => ['来信只提供线索，调查必须由人物完成。']],
            'characters' => [['name' => '林澈', 'role' => '守灯人', 'goal' => '找到失踪师父', 'personality' => '谨慎执着', 'speech_style' => '克制简洁', 'abilities' => '熟悉航道', 'limitations' => '线索必须独立验证']],
            'tone' => ['narrative_style' => '悬疑', 'pov' => '第三人称有限', 'pacing' => '行动推进调查'], 'core_conflict' => '调查者在误导信息中寻找可信证据。',
            'preserved_details' => ['未来来信', '师父失踪十年'], 'open_questions' => []];
    }
}
