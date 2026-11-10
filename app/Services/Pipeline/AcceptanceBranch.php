<?php

namespace App\Services\Pipeline;

use App\Enums\ChapterStatus;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterVersion;
use App\Models\FirstChapterRun;
use App\Models\Foreshadow;
use App\Models\Novel;
use App\Models\NovelOutlinePlan;
use App\Models\NovelSettingConfirmation;
use App\Models\NovelStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** 将作者认可的对照稿导入独立验收分支，原书正式历史保持只读。 */
final class AcceptanceBranch
{
    /** 明确授权十章后原子创建分支；相同来源重复执行只返回已有运行。 */
    public function create(int $sourceNovelId, User $author, string $evidence, string $model, bool $authorized): FirstChapterRun
    {
        if (! $authorized || ! preg_match('/^[a-zA-Z0-9_-]+$/', $evidence) || trim($model) === '' || strlen($model) > 128) {
            throw new InvalidArgumentException('需要明确分支十章授权、有效证据目录及模型');
        }

        return DB::transaction(function () use ($sourceNovelId, $author, $evidence, $model): FirstChapterRun {
            $original = Novel::lockForUpdate()->findOrFail($sourceNovelId);
            $chapter = $original->chapters()->where('number', 1)->firstOrFail();
            $comparison = app(AcceptanceComparison::class)->latest($chapter);
            if ($comparison === null || $comparison['evidence'] !== $evidence) {
                throw new InvalidArgumentException('仅允许导入当前有效对照稿');
            }
            $disk = Storage::disk('local');
            $directory = 'c10-acceptance-evidence/'.$evidence;
            $approvals = collect($disk->files($directory.'/feedback'))->map(fn (string $path): array => json_decode($disk->get($path), true, flags: JSON_THROW_ON_ERROR))
                ->filter(fn (array $row): bool => ($row['actor_id'] ?? null) === $author->id && ($row['body_sha256'] ?? null) === hash('sha256', $comparison['body']) && ($row['source_version_id'] ?? null) === $comparison['source_version_id'])
                ->sortByDesc('created_at');
            if (($approvals->first()['decision'] ?? null) !== 'approve') {
                throw new InvalidArgumentException('缺少当前作者对该正文的最新认可');
            }
            $key = PlanningJson::hash([$sourceNovelId, $evidence, $comparison['body'], $author->id]);
            if ($existing = Novel::where('generation_config->acceptance_branch->key', $key)->first()) {
                return FirstChapterRun::where('novel_id', $existing->id)->firstOrFail();
            }
            $sourceRun = ChapterGenerationRun::findOrFail(ChapterVersion::findOrFail($comparison['source_version_id'])->run_id);
            app(ContentGenerationService::class)->assertFrozenSources($sourceRun);
            $sourcePlan = NovelOutlinePlan::findOrFail($sourceRun->snapshot['outline_plan_id']);
            $confirmation = $sourcePlan->confirmation;
            if ($confirmation->confirmed_by !== $author->id) {
                throw new InvalidArgumentException('原书确认作者不匹配');
            }
            $read = fn (string $file): array => json_decode($disk->get($directory.'/'.$file.'.json'), true, flags: JSON_THROW_ON_ERROR);
            $snapshot = $read('source-snapshot');
            $outline = $read('outline');
            $fine = $read('fine');
            $templates = $read('templates');
            $setting = $snapshot['confirmed_setting'];
            $decisions = $snapshot['decisions'];
            $baseSetting = $setting;
            $baseDecisions = $decisions;
            // 仅允许实验中已明确认可的回镇动机增补，其他设定仍逐项匹配原确认版本。
            if (isset($snapshot['author_approved_motivation'])) {
                $decision = $read('author-decision');
                $motivation = $snapshot['author_approved_motivation'];
                $lastDecision = array_pop($baseDecisions);
                if (($decision['approved_text'] ?? null) !== $motivation || ($lastDecision['answer'] ?? null) !== $motivation
                    || ($baseSetting['characters'][0]['return_motivation'] ?? null) !== $motivation) {
                    throw new InvalidArgumentException('作者动机决定与实验设定不一致');
                }
                unset($baseSetting['characters'][0]['return_motivation']);
            }
            if (PlanningJson::hash($baseSetting) !== PlanningJson::hash($confirmation->setting)
                || PlanningJson::hash($baseDecisions) !== PlanningJson::hash($confirmation->decisions)
                || ($snapshot['confirmation_hash'] ?? null) !== $confirmation->content_hash) {
                throw new InvalidArgumentException('实验设定与已确认来源不一致');
            }
            if (ContentQualityContract::issues($comparison['body'], $original->chapter_length_policy) !== []) {
                throw new InvalidArgumentException('已认可正文仍须通过确定性字数及完整性门禁');
            }
            $lineage = ['key' => $key, 'source_novel_id' => $original->id, 'source_version_id' => $comparison['source_version_id'],
                'source_plan_id' => $sourcePlan->id, 'body_hash' => hash('sha256', $comparison['body']), 'evidence' => $evidence,
                'approval' => $approvals->first(), 'created_at' => now()->toIso8601String(), 'mode' => 'imported_comparison_not_new_generation'];
            $config = [...$original->generation_config, 'invocation_id' => (string) Str::uuid7(), 'model' => $model,
                'prompt_templates' => $templates, 'prompt_variables' => ['title' => $original->title.' · 新验收分支', 'genre' => $original->genre ?? ''], 'acceptance_branch' => $lineage];
            $novel = Novel::create([...$original->only(['genre', 'premise_raw', 'target_chapters', 'chapter_length_policy', 'generation_mode']),
                'title' => $original->title.' · 新验收分支', 'generation_config' => $config, 'status' => NovelStatus::Paused,
                'current_stage' => Stage::Content, 'max_execution_count' => 1000, 'chapters_total' => $original->target_chapters]);
            // 导入阶段明确记录旧来源，执行次数为零，不能冒充新模型生成或复制费用。
            $settingStage = $this->stage($novel, Stage::Setting, ['mode' => 'branch_import', 'source_stage_id' => $confirmation->source_stage_id], ['data' => ['setting' => $setting], 'branch_source' => $lineage]);
            $sourceHash = app(ConfirmSettingService::class)->sourceHash($settingStage);
            $confirmed = NovelSettingConfirmation::create(['novel_id' => $novel->id, 'source_stage_id' => $settingStage->id, 'source_hash' => $sourceHash,
                'setting' => $setting, 'decisions' => $decisions,
                'content_hash' => PlanningJson::hash(['source_hash' => $sourceHash, 'setting' => $setting, 'decisions' => $decisions]),
                'confirmed_by' => $author->id, 'confirmed_at' => now()]);
            $plan = NovelOutlinePlan::create(['novel_id' => $novel->id, 'setting_confirmation_id' => $confirmed->id, 'target_chapters' => $sourcePlan->target_chapters,
                'snapshot' => ['confirmation_hash' => $confirmed->content_hash, 'novel' => $novel->only(['id', 'title', 'genre', 'premise_raw', 'target_chapters', 'chapter_length_policy']), 'confirmed_setting' => $confirmed->setting, 'decisions' => $confirmed->decisions, 'branch_source' => $lineage],
                'config' => [...$sourcePlan->config, 'model' => $model, 'enable_fine_outline' => true], 'roadmap' => $outline['roadmap'], 'handoff' => $outline['handoff'],
                'next_chapter' => $sourcePlan->target_chapters + 1, 'status' => 'completed', 'invocation_id' => (string) Str::uuid7(), 'control_history' => [['action' => 'branch_import', ...$lineage]]]);
            $chapters = $sourcePlan->chapters()->orderBy('chapter_number')->get()->map(fn ($row): array => $row->only(['chapter_number', 'title', 'summary', 'objective', 'conflict', 'hook', 'progression_points']))->all();
            $chapters[0] = $outline['chapters'][0];
            $outlineStage = $this->stage($novel, Stage::Outline, ['outline_plan_id' => $plan->id, 'mode' => 'branch_import', 'source_plan_id' => $sourcePlan->id], ['data' => ['chapters' => $chapters, 'roadmap' => $plan->roadmap], 'branch_source' => $lineage]);
            foreach ($chapters as $data) {
                $plan->chapters()->create([...$data, 'source_stage_id' => $outlineStage->id]);
            }
            $first = $plan->chapters()->where('chapter_number', 1)->firstOrFail();
            // 只继承伏笔计划，旧书的埋设证据不能当作新分支已发生事实。
            foreach (Foreshadow::where('novel_id', $original->id)->orderBy('id')->get() as $foreshadow) {
                app(ForeshadowLedger::class)->plan($novel, $author, $foreshadow->title, $foreshadow->description,
                    $foreshadow->plant_chapter, $foreshadow->target_chapter, (string) Str::uuid7());
            }
            $newChapter = $novel->chapters()->create(['number' => 1, 'title' => $comparison['title'], 'objective' => $first->objective, 'status' => ChapterStatus::Checking]);
            $frozen = [...$snapshot, 'novel' => $plan->snapshot['novel'], 'confirmation_id' => $confirmed->id, 'confirmation_hash' => $confirmed->content_hash,
                'outline_plan_id' => $plan->id, 'source_chapter' => $first->attributesToArray(), 'roadmap' => $plan->roadmap,
                'foreshadow_context' => app(ForeshadowLedger::class)->context($novel, 1), 'branch_source' => $lineage];
            $runConfig = ['version' => ContentExecutionContract::VERSION, 'model' => $model, 'temperature' => 0, 'enable_fine_outline' => true,
                'max_output_tokens' => 8192, 'max_retries' => 0, 'author_id' => $author->id, 'initial_step' => 'check', 'request_cap' => 1,
                'authorization' => ['scope' => 'bounded_content', 'reason' => '已认可首章导入新验收分支后重新检查'], 'accepted_comparison' => $lineage,
                'protocol' => array_combine(['fine', 'draft', 'revise', 'check'], array_map(fn (string $step): string => hash('sha256', ContentExecutionContract::instructions($step)), ['fine', 'draft', 'revise', 'check']))];
            $bodyRun = ChapterGenerationRun::create(['chapter_id' => $newChapter->id, 'outline_chapter_id' => $first->id, 'snapshot' => $frozen, 'config' => $runConfig,
                'input_hash' => PlanningJson::hash([$frozen, $runConfig]), 'plan_data' => null, 'status' => 'pending', 'next_step' => 'check',
                'invocation_id' => (string) Str::uuid7(), 'requests_used' => 0, 'request_limit' => 1, 'retry_count' => 0, 'length_repair_count' => 0,
                'control_history' => [['action' => 'accepted_comparison_import', 'author_id' => $author->id, ...$lineage]]]);
            $fineStage = $this->stage($novel, Stage::FineOutline, ['run_id' => $bodyRun->id, 'mode' => 'branch_import'], ['data' => ['result' => $fine], 'branch_source' => $lineage]);
            $bodyRun->update(['fine_stage_id' => $fineStage->id, 'plan_data' => $fine]);
            $bodyStage = $this->stage($novel, Stage::Content, ['run_id' => $bodyRun->id, 'mode' => 'accepted_comparison_import'], ['body_hash' => $lineage['body_hash'], 'branch_source' => $lineage]);
            $version = ChapterVersion::create(['chapter_id' => $newChapter->id, 'run_id' => $bodyRun->id, 'source_stage_id' => $bodyStage->id, 'version' => 1,
                'title' => $comparison['title'], 'body' => $comparison['body'], 'body_hash' => $lineage['body_hash'], 'character_count' => $comparison['characters'], 'count_version' => ContentSchema::COUNT_VERSION, 'created_at' => now()]);
            $newChapter->update(['current_candidate_version_id' => $version->id]);
            $automation = FirstChapterRun::create(['novel_id' => $novel->id, 'author_id' => $author->id, 'chapter_limit' => 10, 'policy' => FirstChapterRun::policy(3),
                'policy_hash' => PlanningJson::hash(FirstChapterRun::policy(3)), 'commit_key' => (string) Str::uuid7(), 'status' => 'pending', 'step' => 'content',
                'artifacts' => ['confirmation_id' => $confirmed->id, 'outline_plan_id' => $plan->id, 'body_run_id' => $bodyRun->id, 'chapter_number' => 1],
                'history' => [['action' => 'authorized_branch', 'operator_id' => $author->id, ...$lineage]]]);
            $novel->update(['status' => NovelStatus::Pending]);
            app(ContentExecutionService::class)->validate($bodyRun->fresh());

            return $automation;
        });
    }

    /** 创建明确标注导入来源的本地阶段，不复制模型日志或正式事件。
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $output
     */
    private function stage(Novel $novel, Stage $stage, array $input, array $output): NovelStage
    {
        return NovelStage::create(['novel_id' => $novel->id, 'stage' => $stage, 'status' => StageStatus::Completed, 'execution_count' => 0,
            'input' => $input, 'output' => $output, 'started_at' => now(), 'finished_at' => now()]);
    }
}
