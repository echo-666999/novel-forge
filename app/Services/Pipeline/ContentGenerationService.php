<?php

namespace App\Services\Pipeline;

use App\Enums\ChapterStatus;
use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterReview;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Models\ProductionRun;
use App\Models\User;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\ModelProfiles;
use App\Services\Ai\TextRequestOptions;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** 正文唯一业务入口：首次生成、有限恢复及全文核对共用同一执行器。 */
final class ContentGenerationService
{
    /** 创建前冻结参数；仅本次明确确认才允许增加小说总额度。 */
    public function start(Novel $reference, User $author, int $maxOutputTokens = 8192, ?int $requestLimit = null, string $reason = '首章有限生成与检查', ?int $executionLimit = null, ?string $modelOverride = null, int $chapterNumber = 1): ChapterGenerationRun
    {
        $this->outsideTransaction();
        ContentSchema::text($reason);
        if ($maxOutputTokens < 1 || $maxOutputTokens > 8192) {
            throw new InvalidArgumentException('正文Token上限无效');
        }
        $run = DB::transaction(function () use ($reference, $author, $maxOutputTokens, $requestLimit, $reason, $executionLimit, $modelOverride, $chapterNumber): ChapterGenerationRun {
            $novel = Novel::lockForUpdate()->findOrFail($reference->id);
            $plan = $novel->outlinePlans()->where('status', 'completed')->latest('id')->firstOrFail();
            $fine = $plan->config['enable_fine_outline'];
            if (! is_bool($fine) || $novel->generation_mode !== GenerationMode::SettingReal || $novel->status !== NovelStatus::Paused
                || ($chapterNumber === 1 && $novel->current_stage !== ($fine ? Stage::FineOutline : Stage::Content))
                || $chapterNumber !== $novel->chapters_done + 1 || $novel->state_version !== $chapterNumber - 1
                || $novel->chapters()->where('number', '>=', $chapterNumber)->exists()) {
                throw new InvalidArgumentException('不在首次正文生成边界');
            }
            // 有未完成的生产运行时，章号必须在其授权范围内；没有在途运行时作者可逐章手动生成，连续性校验见上。
            $automation = ProductionRun::activeFor($novel->id);
            if ($automation !== null) {
                $automation->assertDelegation($novel->id, $author->id);
                $automation->chapterCommitKey($chapterNumber);
            }
            $this->assertIdle($novel, false);
            $settings = app(GeneralSettings::class);
            $config = $this->configuration($modelOverride ?? $settings->default_model, $settings->temperature, $fine, $maxOutputTokens, $settings->max_retries_per_chapter, $author->id, $reason);
            $base = $fine ? 3 : 2;
            $limit = $this->limit($requestLimit, $base, $config['max_retries']);
            $snapshot = ['novel' => $novel->planningInput(),
                'confirmation_id' => $plan->confirmation->id, 'confirmation_hash' => $plan->confirmation->content_hash,
                'confirmed_setting' => $plan->confirmation->setting, 'decisions' => $plan->confirmation->decisions,
                'source_chapter' => $plan->chapters()->where('chapter_number', $chapterNumber)->firstOrFail()->attributesToArray(), 'outline_plan_id' => $plan->id, 'roadmap' => $plan->roadmap];
            // 验收分支的首章实验模板仅用于首章，后续章冻结这一适用范围。
            if (isset($novel->generation_config['acceptance_branch'])) {
                $snapshot['acceptance_branch'] = $novel->generation_config['acceptance_branch'];
            }
            // 扩展输出容量与按段修复来自模型配置并冻结在新运行中；旧运行配置保持不变。
            $config = $this->withModelCapabilities($config);
            if ($config['extended_output_tokens'] !== null) {
                $config['draft_max_output_tokens'] = $config['extended_output_tokens'];
            }
            if ($chapterNumber > 1) {
                $snapshot['prior_context'] = app(CanonicalChapterContext::class)->build($novel, $chapterNumber);
            }
            // 新运行单独冻结伏笔上下文，历史运行及正式上下文摘要保持原样。
            // 仅新运行冻结上下文预算；历史恢复继续沿用原快照。
            // 作者卷弧仅在新运行建立时冻结，历史恢复沿用父运行快照。
            $structure = app(StoryStructure::class)->context($novel, $chapterNumber);
            if ($structure !== null) {
                $snapshot['author_structure'] = $structure;
            }
            // 场景规划仅在首次启动时冻结，后续恢复保持原版本。
            $scenes = app(ScenePlanning::class)->context($novel, $plan->chapters()->where('chapter_number', $chapterNumber)->firstOrFail());
            if ($scenes !== null) {
                $snapshot['author_scene_plan'] = $scenes;
            }
            // 实体扩展与初始关系仅在新运行冻结，不能改变历史调用。
            $definitions = app(EntityDefinitions::class)->context($novel->id, $chapterNumber);
            if ($definitions !== []) {
                $snapshot['author_entity_definitions'] = $definitions;
            }
            $snapshot['context_policy'] = app(ContextBuilder::class)->policy();
            $snapshot['foreshadow_context'] = app(ForeshadowLedger::class)->context($novel, $chapterNumber);
            $chapter = $novel->chapters()->create(['number' => $chapterNumber, 'title' => $snapshot['source_chapter']['title'], 'objective' => $snapshot['source_chapter']['objective'], 'status' => ChapterStatus::Pending]);
            $config['request_cap'] = $limit;
            $config['initial_step'] = $fine ? 'fine' : 'draft';
            $run = ChapterGenerationRun::create(['chapter_id' => $chapter->id, 'outline_chapter_id' => $snapshot['source_chapter']['id'],
                'snapshot' => $snapshot, 'config' => $config, 'input_hash' => PlanningJson::hash([$snapshot, $config]),
                'plan_data' => $fine ? null : $snapshot['source_chapter'], 'status' => 'pending', 'next_step' => $fine ? 'fine' : 'draft',
                'invocation_id' => (string) Str::uuid7(), 'requests_used' => 0, 'request_limit' => $limit, 'retry_count' => 0, 'length_repair_count' => 0,
                'control_history' => [['action' => 'start', 'author_id' => $author->id, 'reason' => $reason, 'request_limit' => $limit, 'at' => now()->toIso8601String()]]]);
            $this->authorizeExecution($novel, $limit, $executionLimit, $run, $reason);
            $this->assertFrozenSources($run);
            $novel->update(['status' => NovelStatus::Pending, 'pause_requested' => false]);

            return $run;
        });
        app(ContentExecutionService::class)->dispatch($run);

        return $run->refresh();
    }

    /** 旧产物只能通过此入口转为新协议；工作台问题冻结在新运行，旧授权绝不自动续用。
     * @param  list<string>|null  $issues  作者明确指定的问题，空值沿用原恢复入口。
     */
    public function recover(int $parentId, User $author, string $reason, ?int $requestLimit = null, ?int $executionLimit = null, bool $confirmUnknown = false, bool $rewrite = false, ?string $model = null, ?array $issues = null, ?int $expectedVersionId = null, ?int $submissionReviewId = null): ChapterGenerationRun
    {
        $this->outsideTransaction();
        ContentSchema::text($reason);
        $reference = ChapterGenerationRun::findOrFail($parentId);
        [$run, $created] = DB::transaction(function () use ($reference, $author, $reason, $requestLimit, $executionLimit, $confirmUnknown, $rewrite, $model, $issues, $expectedVersionId, $submissionReviewId): array {
            $novel = Novel::lockForUpdate()->findOrFail($reference->chapter->novel_id);
            $parent = ChapterGenerationRun::lockForUpdate()->findOrFail($reference->id);
            $review = $submissionReviewId === null ? null : ChapterReview::findOrFail($submissionReviewId);
            if ($review !== null) {
                $submission = ChapterSubmissionRun::findOrFail($review->submission_run_id);
                if ($submission->status !== 'failed' || $submission->stop_reason !== 'REVIEW_NOT_PASSED'
                    || $submission->chapter_id !== $parent->chapter_id || $submission->config['operator_id'] !== $author->id
                    || $parent->chapter->formal_version_id !== null || $review->version_id !== $parent->chapter->current_candidate_version_id
                    || $review->body_hash !== ChapterVersion::findOrFail($review->version_id)->body_hash
                    || $review->result_hash !== PlanningJson::hash($review->result) || $review->verdict !== 'needs_revision') {
                    throw new InvalidArgumentException('提交评审修订来源已变化');
                }
            }
            // 工作台只能提交绑定版本的问题清单；旧恢复入口不能绕过工作台轮次门禁。
            if ($issues === null && isset($parent->config['workbench'])) {
                throw new InvalidArgumentException('工作台修订必须从指定问题入口继续，达到上限转人工');
            }
            if ($existing = ChapterGenerationRun::where('parent_run_id', $parent->id)->first()) {
                if ($issues !== null && ($existing->config['author_id'] !== $author->id
                    || data_get($existing->config, 'workbench.version_id') !== $expectedVersionId
                    || data_get($existing->config, 'workbench.issues') !== $issues)) {
                    throw new InvalidArgumentException('修订请求已变化，请刷新当前版本');
                }

                return [$existing, false];
            }
            if (! in_array($parent->status, ['paused', 'error', 'completed'], true) || ($parent->stop_reason === 'b04_accepted' && $issues === null && $review === null) || $novel->status !== NovelStatus::Paused) {
                throw new InvalidArgumentException('当前运行不能恢复或验收已经完成');
            }
            $workbench = $issues === null ? null : app(RevisionWorkbench::class)->freeze($parent, $author, $expectedVersionId ?? 0, $issues);
            if ($workbench !== null && $rewrite) {
                throw new InvalidArgumentException('工作台仅允许定点修订，不能整章重写');
            }
            $this->assertIdle($novel, $confirmUnknown);
            $this->assertFrozenSources($parent);
            $source = $parent->chapter->current_candidate_version_id === null ? null : ChapterVersion::findOrFail($parent->chapter->current_candidate_version_id);
            $fine = $parent->config['enable_fine_outline'];
            $plan = $parent->plan_data;
            if (! $fine) {
                $plan ??= $parent->snapshot['source_chapter'];
            }
            $step = $fine && $plan === null ? 'fine' : ($source !== null && ! $rewrite ? 'revise' : 'draft');
            // 仅纠正自动段尾误报：保留旧失败，另建检查运行；真实残句或人工拒绝仍须修订。
            $controls = $parent->control_history;
            $lastControl = end($controls);
            $endingRecheck = ! $rewrite && $source !== null && $parent->stop_reason === 'reading_failed'
                && ($lastControl['acceptance_mode'] ?? null) === 'delegated_policy'
                && count($lastControl['notes'] ?? []) === 1
                && str_starts_with($lastControl['notes'][0], '自动段落完整性检查未通过：')
                && ContentExecutionContract::incompleteParagraphs($source->body) === [];
            if ($endingRecheck) {
                $step = 'check';
            }
            if ($step === 'revise' && count(ContentSchema::anchors($source->body)) < 2) {
                throw new InvalidArgumentException('源正文不足两段，无法保留范围外原文，请明确选择整章重写');
            }
            $settings = app(GeneralSettings::class);
            $config = $this->configuration($model ?? $parent->config['model'], $parent->config['temperature'], $fine, 8192, $settings->max_retries_per_chapter, $author->id, $reason);
            $config = $this->withModelCapabilities($config);
            // 已有验收分支的运行沿用原按段修复行为，与所选模型无关。
            $config['slot_repair'] = $config['slot_repair'] || isset($parent->snapshot['acceptance_branch']);
            if ($config['extended_output_tokens'] !== null) {
                // 多轮修订复查需重新比对当前原文，避免无推理响应直接复述旧缺陷。
                $config['check_max_output_tokens'] = $config['extended_output_tokens'];
                // 细纲恢复后仍可能首次生成正文，保持同一扩展正文容量。
                $config['draft_max_output_tokens'] = $config['extended_output_tokens'];
            }
            if ($step === 'revise' && $config['max_retries'] === 0) {
                throw new InvalidArgumentException('设置禁止修订，不能新建修订运行');
            }
            $base = $step === 'fine' ? 3 : 2;
            $limit = $step === 'revise' ? ($requestLimit ?? 2) : $this->limit($requestLimit, $base, $config['max_retries']);
            if ($endingRecheck) {
                $config['max_retries'] = 0;
                $limit = 1;
                $config['automated_ending_recheck'] = ['version_id' => $source->id, 'body_hash' => $source->body_hash];
            }
            if ($step === 'revise' && $limit !== 2) {
                throw new InvalidArgumentException('恢复范围修订只允许修订和检查各一次');
            }
            $snapshot = $parent->snapshot;
            $snapshot['recovery'] = ['parent_run_id' => $parent->id, 'parent_input_hash' => $parent->input_hash, 'parent_status' => $parent->status,
                'parent_stop_reason' => $parent->stop_reason, 'source_version_id' => $source?->id, 'source_body_hash' => $source?->body_hash,
                'source_lineage' => $source === null ? [] : $this->recoveryLineage($parent, $source), 'plan_hash' => PlanningJson::hash($plan), 'fine_stage_id' => $parent->fine_stage_id];
            $config['request_cap'] = $limit;
            $config['initial_step'] = $step;
            // 支持扩展输出的模型在定点修订时预留推理空间，范围与完整性门禁不因此放宽。
            if ($step === 'revise' && $config['extended_output_tokens'] !== null) {
                $config['revision_max_output_tokens'] = $config['extended_output_tokens'];
            }
            // 冻结前轮响应契约错误，后继请求才能针对真实失败修复，旧响应不改写。
            $failedStage = $novel->stages()->where('input->run_id', $parent->id)->where('status', 'failed')->latest('id')->first();
            $config['recovery_validation_error'] = data_get($failedStage?->output, 'data.validation_error');
            // 推理与文本共用输出容量；仅单段修复的已知耗尽允许增加推理余量。
            $failedResponse = CostLog::where('novel_stage_id', $failedStage?->id)->whereKey(data_get($failedStage?->output, 'data.cost_log_id'))->first();
            if ($config['extended_output_tokens'] !== null
                && (isset($parent->config['repair_max_output_tokens']) || ($failedResponse?->error_category === 'invalid_response' && $failedResponse->output_tokens === 8192))) {
                $config['repair_max_output_tokens'] = $config['extended_output_tokens'];
            }
            // 只携带同一失败阶段的完整正文响应；后继运行冻结副本，旧费用及原始输出不修改。
            if ($step === 'draft' && data_get($failedStage?->output, 'data.step') === 'draft') {
                $failedLog = CostLog::where('novel_stage_id', $failedStage->id)
                    ->whereKey(data_get($failedStage->output, 'data.cost_log_id'))->where('status', 'succeeded')->first();
                $repaired = data_get($failedStage?->output, 'data.repaired_draft');
                if (is_array($repaired) && $repaired['hash'] === PlanningJson::hash($repaired['response']) && $repaired['source_input_hash'] === $parent->input_hash) {
                    // 继承已通过单段校验的合并结果及来源，失败槽位下一轮继续处理，不篡改旧响应。
                    $config['recovery_draft'] = ['cost_log_id' => $failedLog?->id, 'response' => $repaired['response'], 'merged_stage_id' => $failedStage->id, 'merged_hash' => $repaired['hash']];
                } elseif (isset($parent->config['recovery_draft']) && $config['slot_repair']) {
                    $config['recovery_draft'] = $parent->config['recovery_draft'];
                    // 未达字数的完整单段只作为下次修复输入，绝不合入正式正文；截断时保留上次完整尝试。
                    $parts = $failedLog?->structured_output['paragraphs'] ?? null;
                    $config['recovery_repair'] = $parent->config['recovery_repair'] ?? null;
                    if (is_array($parts) && count($parts) === 1) {
                        $slot = array_key_first($parts);
                        $previous = $config['recovery_repair']['paragraphs'][$slot] ?? $config['recovery_draft']['response']['paragraphs'][$slot] ?? null;
                        if (is_string($parts[$slot]) && is_string($previous)) {
                            $budget = ContentExecutionContract::budget($parent->snapshot['novel']['chapter_length_policy'], $plan, $fine);
                            $distance = fn (string $text): int => max($budget['min'] - ContentSchema::count($text), ContentSchema::count($text) - $budget['max'], 0);
                            // 失败仍保留费用原文，但不能用离预算更远的新尝试替换更好的修复输入。
                            if ($distance($parts[$slot]) < $distance($previous)) {
                                $config['recovery_repair'] = ['cost_log_id' => $failedLog->id, 'paragraphs' => $parts];
                            }
                        }
                    }
                } elseif (is_array($failedLog?->structured_output['paragraphs'] ?? null)) {
                    $config['recovery_draft'] = ['cost_log_id' => $failedLog->id, 'response' => $failedLog->structured_output];
                }
            }
            $config['recovery_findings'] = app(ContentExecutionService::class)->findings($parent, $source);
            if ($review !== null) {
                $config['submission_review'] = ['id' => $review->id, 'result_hash' => $review->result_hash, 'version_id' => $review->version_id, 'body_hash' => $review->body_hash];
                foreach ($review->result['findings'] as $index => $finding) {
                    $config['recovery_findings']['submission-review-'.$review->id.'-'.$index] = ['type' => 'submission_review', 'reason' => $finding['reason'], 'evidence_ids' => $finding['evidence_ids'], 'source_body_hash' => $review->body_hash];
                }
            }
            if ($workbench !== null) {
                $config['workbench'] = $workbench;
                foreach ($issues as $index => $issue) {
                    $config['recovery_findings']['author-issue-'.($index + 1)] = ['type' => 'author_issue', 'reason' => $issue, 'source_body_hash' => $source->body_hash];
                }
            }
            $run = ChapterGenerationRun::create(['chapter_id' => $parent->chapter_id, 'outline_chapter_id' => $parent->outline_chapter_id,
                'parent_run_id' => $parent->id, 'source_version_id' => $source?->id, 'snapshot' => $snapshot, 'config' => $config,
                'input_hash' => PlanningJson::hash([$snapshot, $config]), 'plan_data' => $plan, 'fine_stage_id' => $parent->fine_stage_id,
                'status' => 'pending', 'next_step' => $step, 'invocation_id' => (string) Str::uuid7(), 'requests_used' => 0, 'request_limit' => $limit, 'retry_count' => 0, 'length_repair_count' => 0,
                'control_history' => [['action' => 'recover_v14', 'author_id' => $author->id, 'reason' => $reason, 'request_limit' => $limit,
                    'old_model' => $parent->config['model'], 'new_model' => $config['model'], 'unknown_costs_preserved' => CostLog::where('novel_id', $novel->id)->where('status', 'unknown')->pluck('id')->all(), 'at' => now()->toIso8601String()]]]);
            $this->authorizeExecution($novel, $limit, $executionLimit, $run, $reason);
            $this->assertFrozenSources($run);
            $novel->update(['status' => NovelStatus::Pending, 'pause_requested' => false]);

            return [$run, true];
        });
        if ($created) {
            app(ContentExecutionService::class)->dispatch($run);
        }

        return $run->refresh();
    }

    /** 人工正文另存不可变候选并使旧检查失效，保存本身不投递AI请求。 */
    public function saveManualCandidate(int $novelId, int $versionId, User $author, int $draftRevision, string $reason): ChapterVersion
    {
        ContentSchema::text($reason);

        return DB::transaction(function () use ($novelId, $versionId, $author, $draftRevision, $reason): ChapterVersion {
            $novel = Novel::lockForUpdate()->findOrFail($novelId);
            $source = app(ManualContentDrafts::class)->source($novelId, $versionId, $author);
            $this->assertIdle($novel, false);
            $parent = ChapterGenerationRun::findOrFail($source->run_id);
            $draft = DB::table('manual_content_drafts')->where(['chapter_id' => $source->chapter_id, 'actor_id' => $author->id])->first();
            if ($draft === null || (int) $draft->revision !== $draftRevision || (int) $draft->base_version_id !== $source->id) {
                throw new InvalidArgumentException('人工工作草稿已变化，请先保存并刷新');
            }
            ContentSchema::text($draft->title);
            ContentSchema::text($draft->body);
            if (ContentQualityContract::issues($draft->body, $parent->snapshot['novel']['chapter_length_policy']) !== []) {
                throw new InvalidArgumentException('正文未通过字数或完整性检查，工作草稿已保留，请继续编辑');
            }
            if ($draft->title === $source->title && $draft->body === $source->body) {
                throw new InvalidArgumentException('正文和标题没有改变，无需创建相同候选');
            }
            $snapshot = $parent->snapshot;
            $snapshot['recovery'] = ['parent_run_id' => $parent->id, 'parent_input_hash' => $parent->input_hash, 'parent_status' => $parent->status,
                'parent_stop_reason' => $parent->stop_reason, 'source_version_id' => $source->id, 'source_body_hash' => $source->body_hash,
                'source_lineage' => $this->recoveryLineage($parent, $source), 'plan_hash' => PlanningJson::hash($parent->plan_data), 'fine_stage_id' => $parent->fine_stage_id];
            $config = $this->configuration($parent->config['model'], $parent->config['temperature'], $parent->config['enable_fine_outline'], 8192, 0, $author->id, $reason);
            $config['initial_step'] = 'check';
            $config['request_cap'] = 1;
            $config['manual_edit'] = ['source_version_id' => $source->id, 'body_hash' => hash('sha256', $draft->body), 'title' => $draft->title, 'author_id' => $author->id];
            $run = ChapterGenerationRun::create(['chapter_id' => $source->chapter_id, 'outline_chapter_id' => $parent->outline_chapter_id, 'parent_run_id' => $parent->id,
                'source_version_id' => $source->id, 'snapshot' => $snapshot, 'config' => $config, 'input_hash' => PlanningJson::hash([$snapshot, $config]),
                'plan_data' => $parent->plan_data, 'fine_stage_id' => $parent->fine_stage_id, 'status' => 'paused', 'next_step' => 'check', 'stop_reason' => 'manual_draft_saved',
                'invocation_id' => (string) Str::uuid7(), 'requests_used' => 0, 'request_limit' => 1, 'retry_count' => 0, 'length_repair_count' => 0,
                'control_history' => [['action' => 'manual_edit_saved', 'author_id' => $author->id, 'reason' => $reason, 'at' => now()->toIso8601String()]]]);
            $stage = NovelStage::create(['novel_id' => $novelId, 'stage' => Stage::Content, 'status' => StageStatus::Completed,
                'execution_count' => 0, 'input' => ['run_id' => $run->id, 'author_id' => $author->id, 'mode' => 'manual_edit'],
                'output' => ['mode' => 'manual_edit', 'body_hash' => $config['manual_edit']['body_hash']], 'started_at' => now(), 'finished_at' => now()]);
            $version = ChapterVersion::create(['chapter_id' => $source->chapter_id, 'run_id' => $run->id, 'source_stage_id' => $stage->id, 'parent_version_id' => $source->id,
                'version' => (int) ChapterVersion::where('chapter_id', $source->chapter_id)->max('version') + 1, 'title' => $draft->title, 'body' => $draft->body,
                'body_hash' => $config['manual_edit']['body_hash'], 'character_count' => ContentSchema::count($draft->body), 'count_version' => ContentSchema::COUNT_VERSION, 'created_at' => now()]);
            $source->chapter->update(['current_candidate_version_id' => $version->id, 'title' => $version->title, 'progression_result' => null, 'status' => ChapterStatus::Checking]);
            DB::table('manual_content_drafts')->where('id', $draft->id)->update(['base_version_id' => $version->id, 'revision' => $draftRevision + 1, 'updated_at' => now()]);
            $this->assertFrozenSources($run->fresh());

            return $version;
        });
    }

    /** 同一授权只能补未执行步骤，不扩大冻结请求上限或重发未知付费意图。 */
    public function continue(int $id, bool $confirmUnknown = false, ?int $requestLimit = null, ?int $executionLimit = null, ?string $reason = null, ?int $authorId = null): ChapterGenerationRun
    {
        $this->outsideTransaction();
        $run = DB::transaction(function () use ($id, $requestLimit, $executionLimit, $reason, $authorId): ChapterGenerationRun {
            $reference = ChapterGenerationRun::findOrFail($id);
            // 与暂停及消费入口统一先锁小说再锁运行，避免并发恢复锁顺序反转。
            $novel = Novel::lockForUpdate()->findOrFail($reference->chapter->novel_id);
            $run = ChapterGenerationRun::lockForUpdate()->findOrFail($id);
            $this->assertFrozenSources($run);
            if (($run->config['version'] ?? null) !== ContentExecutionContract::VERSION || $run->status !== 'paused'
                || ! in_array($run->stop_reason, ['execution_limit', 'dispatch_failed', 'reading_failed', 'user_paused', 'manual_draft_saved'], true) || $novel->status !== NovelStatus::Paused
                || ($requestLimit !== null && $requestLimit !== $run->request_limit)) {
                throw new InvalidArgumentException('不能直接继续；旧协议或已发送的失败请求须使用显式恢复');
            }
            if (isset($run->config['manual_edit']) && ($authorId !== $run->config['author_id'])) {
                throw new InvalidArgumentException('人工候选检查只能由原编辑作者授权');
            }
            $this->assertIdle($novel, false);
            if ($run->stop_reason === 'reading_failed') {
                if (! app(ContentExecutionService::class)->canRevise($run, false)) {
                    throw new InvalidArgumentException('全文修订额度已经耗尽，需另行明确授权恢复');
                }
                $run->next_step = 'revise';
            }
            ContentSchema::text($reason ?? '');
            $this->authorizeExecution($novel, $run->request_limit - $run->requests_used, $executionLimit, $run, $reason);
            $run->update(['status' => 'pending', 'stop_reason' => null, 'invocation_id' => (string) Str::uuid7(),
                'control_history' => [...$run->control_history, ['action' => 'continue', 'author_id' => $authorId, 'reason' => $reason, 'at' => now()->toIso8601String()]]]);
            $novel->update(['status' => NovelStatus::Pending, 'pause_requested' => false]);

            return $run;
        });
        app(ContentExecutionService::class)->dispatch($run);

        return $run->refresh();
    }

    /** 历史消息只拒绝并留日志；新协议始终交给唯一执行器。 */
    public function runStep(int $id, string $token, AiTextGenerationService $ai, ProgressionChecker $checker): void
    {
        app(ContentExecutionService::class)->runStep($id, $token, $ai);
    }

    /** 队列失败回调沿用同一幂等中断处理。 */
    public function interrupted(int $id, string $token): void
    {
        app(ContentExecutionService::class)->interrupted($id, $token);
    }

    /** 新旧协议共同核对规划来源；历史解析器没有生成能力。 */
    public function assertFrozenSources(ChapterGenerationRun $run): void
    {
        if (($run->config['version'] ?? null) === ContentExecutionContract::VERSION) {
            app(ContentExecutionService::class)->validate($run);
        } else {
            app(LegacyContentSources::class)->assertSources($run);
        }
    }

    /** 同章祖先链必须逐环核对输入摘要。
     * @return list<array{run_id: int, input_hash: string}>
     */
    public function recoveryLineage(ChapterGenerationRun $parent, ChapterVersion $source): array
    {
        return app(LegacyContentSources::class)->sourceLineage($parent, $source);
    }

    /** 全文阅读独立于模型通过；不通过只保存意见，不暗中继续付费。 */
    public function recordReading(int $id, User $author, bool $accepted, array $notes): ChapterGenerationRun
    {
        return app(ContentExecutionService::class)->recordReading($id, $author, $accepted, $notes);
    }

    /** 启动时将设置中的修订次数约束为零或一次，保留原全局设置。
     * @return array<string, mixed>
     */
    /** 冻结模型配置中的扩展能力；运行建立后不再随配置文件变化。
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withModelCapabilities(array $config): array
    {
        $profile = ModelProfiles::profile($config['model']);

        return [...$config, 'extended_output_tokens' => $profile['extended_output_tokens'], 'slot_repair' => $profile['slot_repair']];
    }

    private function configuration(string $model, float $temperature, bool $fine, int $tokens, int $retries, int $authorId, string $reason): array
    {
        TextRequestOptions::setting($temperature);
        if (trim($model) === '' || strlen($model) > 128 || $retries < 0) {
            throw new InvalidArgumentException('模型或修订设置无效');
        }
        $config = ['version' => ContentExecutionContract::VERSION, 'model' => trim($model), 'temperature' => $temperature,
            'enable_fine_outline' => $fine, 'max_output_tokens' => $tokens, 'max_retries' => min(1, $retries), 'author_id' => $authorId,
            'authorization' => ['scope' => 'bounded_content', 'reason' => $reason, 'at' => now()->toIso8601String()],
            'protocol' => array_combine(['fine', 'draft', 'revise', 'check'], array_map(fn (string $step): string => hash('sha256', ContentExecutionContract::instructions($step)), ['fine', 'draft', 'revise', 'check']))];

        return json_decode(json_encode($config, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    /** 硬上限不能通过表单或CLI增大；允许明确选择更小预算。 */
    private function limit(?int $requested, int $base, int $retries): int
    {
        $maximum = $base + 2 * $retries;
        $limit = $requested ?? $maximum;
        if ($limit < $base || $limit > $maximum) {
            throw new InvalidArgumentException('请求额度超出本次有限生成范围');
        }

        return $limit;
    }

    /** 有活动请求时禁止创建新运行；未知结果必须单独确认风险。 */
    private function assertIdle(Novel $novel, bool $confirmUnknown): void
    {
        // 已显式确认的旧未知费用仍保留，只解除这些请求对后续章节的重复阻塞。
        $acknowledged = ProductionRun::latestFor($novel->id)?->acknowledgedUnknownLogIds() ?? [];
        if ($novel->stages()->where('status', 'running')->exists() || ChapterGenerationRun::whereHas('chapter', fn ($q) => $q->where('novel_id', $novel->id))->whereIn('status', ['pending', 'running'])->exists()
            || CostLog::where('novel_id', $novel->id)->where('status', 'pending')->exists()
            || (! $confirmUnknown && CostLog::where('novel_id', $novel->id)->where('status', 'unknown')->whereNotIn('id', $acknowledged)->exists())) {
            throw new InvalidArgumentException('存在活动步骤或尚未确认的未知调用');
        }
    }

    /** 小说总额度只在用户明确提供新值时调整，并留下审计。 */
    private function authorizeExecution(Novel $novel, int $needed, ?int $requested, ChapterGenerationRun $run, string $reason): void
    {
        $limit = $requested ?? $novel->max_execution_count;
        if ($needed < 1 || $limit < $novel->max_execution_count || $limit < $novel->execution_count + $needed || $limit > 2147483647) {
            throw new InvalidArgumentException('小说剩余总额度不足，须明确确认新总上限');
        }
        if ($limit !== $novel->max_execution_count) {
            $run->update(['control_history' => [...$run->control_history, ['action' => 'execution_limit', 'old' => $novel->max_execution_count, 'new' => $limit, 'reason' => $reason, 'at' => now()->toIso8601String()]]]);
            $novel->update(['max_execution_count' => $limit]);
        }
    }

    /** 外部请求不能处于外层事务，以确保费用意图先提交。 */
    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new InvalidArgumentException('正文入口不能处于外层事务');
        }
    }
}
