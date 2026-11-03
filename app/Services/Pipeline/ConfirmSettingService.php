<?php

namespace App\Services\Pipeline;

use App\Enums\GenerationMode;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Models\Novel;
use App\Models\NovelSettingConfirmation;
use App\Models\NovelStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 最小作者确认入口：先解决阻塞问题，再保存独立快照，不改写模型原始候选。 */
final class ConfirmSettingService
{
    /** 读取可确认的真实设定，旧模拟产物不能作为来源。 */
    public function source(Novel $novel): NovelStage
    {
        $stage = $novel->stages()->where('stage', Stage::Setting->value)->where('status', StageStatus::Completed->value)->latest('id')->first();
        if ($novel->generation_mode !== GenerationMode::SettingReal || $stage === null || data_get($stage->output, 'simulation') !== false) {
            throw ValidationException::withMessages(['setting' => '缺少成功的真实设定，不能确认。']);
        }
        SettingSchema::parse(PlanningJson::encode(data_get($stage->output, 'data.setting')));

        return $stage;
    }

    /** 摘要覆盖来源身份、状态和完整输出，不依赖 JSONB 对象键序。 */
    public function sourceHash(NovelStage $stage): string
    {
        return PlanningJson::hash($stage->only(['id', 'novel_id', 'stage', 'status', 'output']));
    }

    /** 首轮保留原候选且不预填决定；重开可沿用作者旧决定，确认框仍须重新勾选。
     * @return array<string, mixed>
     */
    public function draft(Novel $novel): array
    {
        $stage = $this->source($novel);
        $setting = $stage->output['data']['setting'];

        $sourceHash = $this->sourceHash($stage);
        $confirmed = $novel->settingConfirmations()->where('source_hash', $sourceHash)->latest('id')->first();
        $answers = [];
        foreach ($setting['open_questions'] as $index => $question) {
            $answers[] = [...$question, 'answer' => data_get($confirmed?->decisions, $index.'.answer', '')];
        }

        return ['source_hash' => $sourceHash, 'setting' => $confirmed?->setting ?? $setting,
            'answers' => $answers,
            'acknowledged' => false];
    }

    /** 确认与付费启动分离；重复确认同样内容只返回原快照。
     * @param  array<string, mixed>  $input
     */
    public function confirm(Novel $reference, User $author, array $input): NovelSettingConfirmation
    {
        return DB::transaction(function () use ($reference, $author, $input): NovelSettingConfirmation {
            $novel = Novel::query()->lockForUpdate()->findOrFail($reference->id);
            $stage = $this->source($novel);
            $sourceHash = $this->sourceHash($stage);
            if (! is_string($input['source_hash'] ?? null) || ! hash_equals($sourceHash, $input['source_hash'])) {
                throw ValidationException::withMessages(['source_hash' => '设定来源已变化，请重新打开确认表单。']);
            }
            if (($input['acknowledged'] ?? false) !== true) {
                throw ValidationException::withMessages(['acknowledged' => '请明确确认修订内容和问题答案。']);
            }
            if ($novel->stages()->where('status', StageStatus::Running->value)->exists() || $novel->outlinePlans()->whereIn('status', ['pending', 'running', 'paused', 'error'])->exists()) {
                throw ValidationException::withMessages(['setting' => '已有运行或未结束的大纲计划，不能替换确认版本。']);
            }
            $decisions = [];
            $remaining = [];
            foreach ($stage->output['data']['setting']['open_questions'] as $index => $question) {
                $answer = data_get($input, 'answers.'.$index.'.answer', '');
                if (! is_string($answer) || ($question['blocking'] && preg_match('/(*UCP)\S/u', $answer) !== 1)) {
                    throw ValidationException::withMessages(['answers.'.$index.'.answer' => '请回答此阻塞问题，并将决定体现在设定修订中。']);
                }
                $decisions[] = [...$question, 'answer' => trim($answer)];
                if (preg_match('/(*UCP)\S/u', $answer) !== 1) {
                    $remaining[] = $question;
                }
            }
            if (! is_array($input['setting'] ?? null)) {
                throw ValidationException::withMessages(['setting' => '缺少确认设定内容。']);
            }
            // 问题身份和阻塞性以原始来源为准，不能通过删掉表单问题绕过确认。
            $setting = SettingSchema::parse(PlanningJson::encode([...$input['setting'], 'open_questions' => $remaining]));
            $hash = PlanningJson::hash(['source_hash' => $sourceHash, 'setting' => $setting, 'decisions' => $decisions]);

            return NovelSettingConfirmation::query()->firstOrCreate(['novel_id' => $novel->id, 'content_hash' => $hash], [
                'source_stage_id' => $stage->id, 'source_hash' => $sourceHash, 'setting' => $setting, 'decisions' => $decisions,
                'confirmed_by' => $author->id, 'confirmed_at' => now(),
            ]);
        });
    }
}
