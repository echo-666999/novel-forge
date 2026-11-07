<?php

namespace App\Services\Pipeline;

use App\Enums\NovelStatus;
use App\Jobs\PrepareChapterSubmissionJob;
use App\Models\ChapterEventExtraction;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterReview;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterSubmissionValidation;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelSettingConfirmation;
use App\Models\StoryEntity;
use App\Models\StoryStateProjection;
use App\Models\User;
use App\Services\Ai\AiGenerationException;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\TextRequestOptions;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/** 有限提交准备：评审和提取各一次，不续写、不替代作者批准。 */
final class ChapterSubmissionService
{
    /** 每轮明确授权最多两次请求；只有显式指定失败前驱才建立不可变后继。 */
    public function prepare(int $versionId, User $operator, int $expectedState, string $reason, ?int $executionLimit = null, bool $dispatch = true, ?int $retryOf = null, ?string $modelOverride = null): ChapterSubmissionRun
    {
        if (DB::transactionLevel() !== 0) {
            throw new InvalidArgumentException('OUTER_TRANSACTION');
        }
        ContentSchema::text($reason);
        if ($expectedState < 0) {
            throw new InvalidArgumentException('STATE_INVALID');
        }
        $version = ChapterVersion::findOrFail($versionId);
        [$run, $created] = DB::transaction(function () use ($version, $operator, $expectedState, $reason, $executionLimit, $retryOf, $modelOverride): array {
            $novel = Novel::lockForUpdate()->findOrFail($version->chapter->novel_id);
            $predecessor = null;
            if ($retryOf !== null) {
                $predecessor = ChapterSubmissionRun::lockForUpdate()->findOrFail($retryOf);
                if ($predecessor->version_id !== $version->id || $predecessor->expected_state_version !== $expectedState
                    || $predecessor->config['operator_id'] !== $operator->id || $predecessor->status !== 'failed') {
                    throw new InvalidArgumentException('RETRY_SOURCE_INVALID');
                }
                if ($executionLimit === null) {
                    throw new InvalidArgumentException('EXECUTION_LIMIT_CONFIRMATION_REQUIRED');
                }
            }
            $existingQuery = ChapterSubmissionRun::where('version_id', $version->id)->where('expected_state_version', $expectedState);
            $existing = $retryOf === null ? $existingQuery->where('attempt', 1)->first() : $existingQuery->where('predecessor_id', $retryOf)->first();
            if ($existing !== null) {
                if ($existing->config['operator_id'] !== $operator->id) {
                    throw new InvalidArgumentException('AUTHOR_MISMATCH');
                }

                return [$existing, false];
            }
            $bodyRun = ChapterGenerationRun::findOrFail($version->run_id);
            app(ContentGenerationService::class)->assertFrozenSources($bodyRun);
            $confirmation = NovelSettingConfirmation::findOrFail($bodyRun->snapshot['confirmation_id']);
            if ($confirmation->confirmed_by !== $operator->id || $bodyRun->stop_reason !== 'b04_accepted') {
                throw new InvalidArgumentException('AUTHOR_OR_CONTENT_NOT_READY');
            }
            foreach ($confirmation->setting['characters'] as $character) {
                $definition = ['name' => $character['name'], 'type' => 'character', 'aliases' => [], 'description' => $character];
                $entity = StoryEntity::where('novel_id', $novel->id)->where('type', 'character')->where('normalized_name', SubmissionValidator::name($character['name']))->first();
                if ($entity === null) {
                    StoryEntity::create(['novel_id' => $novel->id, 'type' => 'character', 'name' => $character['name'], 'normalized_name' => SubmissionValidator::name($character['name']),
                        'aliases' => [], 'definition' => $definition, 'definition_hash' => PlanningJson::hash($definition),
                        'origin' => ['kind' => 'confirmed_setting', 'confirmation_id' => $confirmation->id, 'confirmation_hash' => $confirmation->content_hash],
                        'approved_by' => $confirmation->confirmed_by, 'approved_at' => $confirmation->confirmed_at]);
                } elseif ($entity->definition_hash !== PlanningJson::hash($definition)) {
                    throw new InvalidArgumentException('ENTITY_DEFINITION_CHANGED');
                }
            }
            $snapshot = $this->source($version, $expectedState);
            // 后继只替换请求契约，不能借重试切换正文、实体目录或前序故事状态。
            if ($predecessor !== null && (PlanningJson::hash([$predecessor->snapshot, $predecessor->config]) !== $predecessor->input_hash
                || PlanningJson::hash($snapshot) !== PlanningJson::hash($predecessor->snapshot))) {
                throw new InvalidArgumentException('RETRY_SOURCE_STALE');
            }
            $settings = app(GeneralSettings::class);
            // 显式单次模型选择冻结到运行，不改全局设置及前驱记录。
            $model = trim($modelOverride ?? $settings->default_model);
            if ($model === '' || strlen($model) > 128) {
                throw new InvalidArgumentException('MODEL_INVALID');
            }
            $limit = $executionLimit ?? $novel->max_execution_count;
            if ($limit < $novel->max_execution_count || $limit < $novel->execution_count + 2 || $limit > 2147483647) {
                throw new InvalidArgumentException('EXECUTION_LIMIT_CONFIRMATION_REQUIRED');
            }
            $config = ['version' => SubmissionContract::VERSION, 'model' => $model, 'temperature' => 0, 'request_limit' => 2,
                'operator_id' => $operator->id, 'reason' => $reason, 'protocol' => ['review' => hash('sha256', SubmissionContract::instructions('review')), 'extract' => hash('sha256', SubmissionContract::instructions('extract'))]];
            if ($predecessor !== null) {
                $config['predecessor'] = ['id' => $predecessor->id, 'input_hash' => $predecessor->input_hash, 'stop_reason' => $predecessor->stop_reason];
                $config['repair_feedback'] = $this->repairFeedback($predecessor);
            }
            $run = ChapterSubmissionRun::create(['novel_id' => $novel->id, 'chapter_id' => $version->chapter_id, 'version_id' => $version->id, 'body_hash' => $version->body_hash,
                'attempt' => ($predecessor?->attempt ?? 0) + 1, 'predecessor_id' => $retryOf,
                'expected_state_version' => $expectedState, 'snapshot' => $snapshot, 'config' => $config, 'input_hash' => PlanningJson::hash([$snapshot, $config]),
                'status' => 'pending', 'step' => 'review', 'invocation_id' => (string) Str::uuid7(), 'requests_used' => 0, 'request_limit' => 2,
                'control_history' => [['action' => 'authorize_preparation', 'operator_id' => $operator->id, 'reason' => $reason, 'request_limit' => 2,
                    'old_execution_limit' => $novel->max_execution_count, 'new_execution_limit' => $limit, 'at' => now()->toIso8601String()]]]);
            $novel->update(['max_execution_count' => $limit]);

            return [$run, true];
        });
        if ($created && $dispatch) {
            $this->dispatch($run);
        }

        return $run->refresh();
    }

    /** 当前批准实体目录按稳定编号排序，并校验定义未被绕过模型直接覆盖。
     * @return array<string, array<string, mixed>>
     */
    public function catalog(int $novelId): array
    {
        $catalog = [];
        $number = Novel::findOrFail($novelId)->chapters_done + 1;
        foreach (StoryEntity::where('novel_id', $novelId)->orderBy('id')->get() as $entity) {
            if ($entity->definition_hash !== PlanningJson::hash($entity->definition) || $entity->normalized_name !== SubmissionValidator::name($entity->name) || $entity->name !== $entity->definition['name'] || $entity->type !== $entity->definition['type']
                || PlanningJson::hash($entity->aliases) !== PlanningJson::hash($entity->definition['aliases'])) {
                throw new InvalidArgumentException('ENTITY_DEFINITION_CHANGED');
            }
            // 新实体审批版本供当前准备使用；已有冻结快照仍保留原定义与摘要。
            $effective = app(EntityDefinitions::class)->effective($entity, $number);
            $catalog['entity-'.$entity->id] = ['id' => $entity->id, 'name' => $entity->name, 'type' => $entity->type, 'aliases' => $effective['aliases'],
                'definition' => $effective, 'definition_hash' => PlanningJson::hash($effective), 'origin' => $entity->origin, 'approved_by' => $entity->approved_by, 'approved_at' => $entity->approved_at->toIso8601String()];
        }
        ksort($catalog, SORT_STRING);

        return $catalog;
    }

    /** 来源包含正文、规划、推进、实体及前序状态，不包含可变消费计数。
     * @return array<string, mixed>
     */
    public function source(ChapterVersion $version, int $expectedState): array
    {
        $version->refresh();
        $chapter = $version->chapter->fresh();
        $novel = $chapter->novel->fresh();
        $bodyRun = ChapterGenerationRun::findOrFail($version->run_id);
        if ($novel->status !== NovelStatus::Paused) {
            throw new InvalidArgumentException('NOVEL_NOT_PAUSED');
        }
        if ($novel->state_version !== $expectedState || $chapter->number !== $novel->chapters_done + 1) {
            throw new InvalidArgumentException('STALE_STATE');
        }
        if ($chapter->current_candidate_version_id !== $version->id || $chapter->formal_version_id !== null || $bodyRun->stop_reason !== 'b04_accepted') {
            throw new InvalidArgumentException('CONTENT_STALE');
        }
        app(ContentGenerationService::class)->assertFrozenSources($bodyRun);
        if (($chapter->progression_result['version_id'] ?? null) !== $version->id || ($chapter->progression_result['verdict'] ?? null) !== 'pass'
            || hash('sha256', $version->body) !== $version->body_hash || ContentSchema::count($version->body) !== $version->character_count) {
            throw new InvalidArgumentException('CONTENT_STALE');
        }
        $projection = $expectedState === 0 ? null : StoryStateProjection::where('novel_id', $novel->id)->where('state_version', $expectedState)->first();
        if ($expectedState > 0 && ($projection === null || $projection->state_hash !== PlanningJson::hash($projection->state))) {
            throw new InvalidArgumentException('STATE_PROJECTION_INVALID');
        }

        return ['version_id' => $version->id, 'body_hash' => $version->body_hash, 'body_run_id' => $bodyRun->id, 'body_run_input_hash' => $bodyRun->input_hash,
            'body_plan' => $bodyRun->plan_data ?? $bodyRun->snapshot['source_chapter'], 'content_sources' => $bodyRun->snapshot,
            'progression_hash' => PlanningJson::hash($chapter->progression_result), 'expected_state_version' => $expectedState,
            'entity_catalog' => $this->catalog($novel->id), 'state' => $projection?->state ?? ['entities' => []], 'state_hash' => $projection?->state_hash ?? PlanningJson::hash(['entities' => []])];
    }

    /** 每次发送及提交前重验冻结来源，正文或实体改变即失效。 */
    public function assertCurrent(ChapterSubmissionRun $run): void
    {
        if ($run->config['version'] !== SubmissionContract::VERSION || $run->request_limit !== 2 || $run->requests_used > 2
            || PlanningJson::hash([$run->snapshot, $run->config]) !== $run->input_hash) {
            throw new InvalidArgumentException('SUBMISSION_CONTRACT_CHANGED');
        }
        foreach (['review', 'extract'] as $step) {
            if ($run->config['protocol'][$step] !== hash('sha256', SubmissionContract::instructions($step))) {
                throw new InvalidArgumentException('SUBMISSION_CONTRACT_CHANGED');
            }
        }
        if (PlanningJson::hash($run->snapshot) !== PlanningJson::hash($this->source($run->version, $run->expected_state_version))) {
            throw new InvalidArgumentException('SOURCE_STALE');
        }
    }

    /** 本地结构失败不发送请求；领用提交后才执行外部HTTP。 */
    public function execute(int $id, string $token, bool $dispatchNext = true): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new InvalidArgumentException('OUTER_TRANSACTION');
        }
        $claim = DB::transaction(function () use ($id, $token): ?array {
            $reference = ChapterSubmissionRun::findOrFail($id);
            $novel = Novel::lockForUpdate()->findOrFail($reference->novel_id);
            $run = ChapterSubmissionRun::lockForUpdate()->findOrFail($id);
            if ($run->status !== 'pending' || $run->invocation_id !== $token) {
                return null;
            }
            try {
                $this->assertCurrent($run);
                $issues = SubmissionContract::structuralIssues($run->version->body, $run->snapshot['content_sources']['novel']['chapter_length_policy']);
                if ($issues !== []) {
                    throw new InvalidArgumentException('STRUCTURE_BLOCKED:'.implode(',', $issues));
                }
                if ($run->requests_used >= 2 || $novel->execution_count >= $novel->max_execution_count) {
                    throw new InvalidArgumentException('REQUEST_BUDGET_EXHAUSTED');
                }
                if (CostLog::where('novel_id', $novel->id)->where('status', 'pending')->exists()
                    || ChapterSubmissionRun::where('novel_id', $novel->id)->where('status', 'running')->exists()) {
                    throw new InvalidArgumentException('REQUEST_ALREADY_ACTIVE');
                }
            } catch (InvalidArgumentException $exception) {
                $run->update(['status' => 'failed', 'stop_reason' => $exception->getMessage()]);

                return null;
            }
            $run->update(['status' => 'running', 'requests_used' => $run->requests_used + 1]);
            $novel->update(['execution_count' => $novel->execution_count + 1]);

            return [$run, $run->version];
        });
        if ($claim === null) {
            return;
        }
        [$run, $version] = $claim;
        $purpose = $run->step;
        $log = null;
        $result = null;
        $error = null;
        try {
            $anchors = ContentSchema::anchors($version->body);
            $context = ['anchors' => $anchors, 'entity_catalog' => $run->snapshot['entity_catalog'], 'run_id' => $run->id, 'version_id' => $version->id, 'body_hash' => $version->body_hash];
            // 评审与提取继承正文冻结预算，并保留各自提交来源摘要。
            if (isset($run->snapshot['content_sources']['context_policy'])) {
                $context['context_policy'] = $run->snapshot['content_sources']['context_policy'];
                $context['context_source'] = ['submission_run_id' => $run->id, 'input_hash' => $run->input_hash, 'version_id' => $version->id, 'chapter_number' => $run->chapter->number];
            }
            $payload = ['prior_context' => $run->snapshot['content_sources']['prior_context'] ?? null, 'version_id' => $version->id, 'body_hash' => $version->body_hash, 'evidence_catalog' => $anchors, 'word_policy' => $run->snapshot['content_sources']['novel']['chapter_length_policy'],
                // 字数使用与本地硬门禁相同的确定性计数，避免模型按段数估计而误拒合格正文。
                'body_measurement' => ['visible_non_whitespace_characters' => ContentSchema::count($version->body),
                    'method' => 'Unicode可见非空白正文字符，包含标点，不包含标题；字数判断使用此程序实测值，不按段数或Token估算。'],
                'confirmed_setting' => $run->snapshot['content_sources']['confirmed_setting'], 'author_decisions' => $run->snapshot['content_sources']['decisions'],
                'chapter_plan' => $run->snapshot['body_plan'], 'rule_catalog' => ContentQualityContract::rules($run->snapshot['content_sources']),
                'entity_catalog' => $run->snapshot['entity_catalog'], 'prior_state' => $run->snapshot['state'], 'reading_hints' => ContentReadingAudit::inspect($version->body)];
            if ($purpose === 'extract' && isset($run->config['repair_feedback'])) {
                $payload['rejected_extraction'] = $run->config['repair_feedback'];
            }
            // 评审与提取使用同一份章前伏笔输入，旧快照不补写新字段。
            if (array_key_exists('foreshadow_context', $run->snapshot['content_sources'])) {
                $payload['foreshadow_context'] = $run->snapshot['content_sources']['foreshadow_context'];
            }
            $log = app(AiTextGenerationService::class)->generate('冻结提交输入：'.PlanningJson::encode($payload), 'real', $run->config['model'], $run->novel_id, null,
                'submission-'.$purpose.'-'.SubmissionContract::VERSION, TextRequestOptions::submission($purpose, $context));
            if (preg_match('/^'.preg_quote($run->config['model'], '/').'(-\d{4}-\d{2}-\d{2})?$/D', $log->model ?? '') !== 1) {
                throw new InvalidArgumentException('MODEL_MISMATCH');
            }
            $data = ContentSchema::decode($log->response_text);
            $result = $purpose === 'review' ? SubmissionContract::review($data, $version->body) : app(SubmissionValidator::class)->normalize($data, $version->body, $run->snapshot['state']);
        } catch (AiGenerationException $exception) {
            $log = $exception->costLogId === null ? null : CostLog::find($exception->costLogId);
            $error = $exception->status === 'unknown' ? 'UNKNOWN_RESPONSE' : 'PROVIDER_FAILED';
        } catch (Throwable $exception) {
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : ($log?->status === 'succeeded' ? 'INVALID_RESPONSE' : 'AUDIT_OR_LOCAL_FAILURE');
        }
        $next = DB::transaction(function () use ($id, $token, $purpose, $result, $log, $error): bool {
            $reference = ChapterSubmissionRun::findOrFail($id);
            Novel::lockForUpdate()->findOrFail($reference->novel_id);
            $run = ChapterSubmissionRun::lockForUpdate()->findOrFail($id);
            if ($run->status !== 'running' || $run->invocation_id !== $token || $run->step !== $purpose) {
                return false;
            }
            try {
                $this->assertCurrent($run);
            } catch (InvalidArgumentException $exception) {
                $run->update(['status' => 'failed', 'stop_reason' => $exception->getMessage()]);

                return false;
            }
            if ($error !== null) {
                $run->update(['status' => 'failed', 'stop_reason' => $error]);

                return false;
            }
            $identity = ['submission_run_id' => $run->id, 'novel_id' => $run->novel_id, 'chapter_id' => $run->chapter_id, 'version_id' => $run->version_id,
                'body_hash' => $run->body_hash, 'result' => $result, 'result_hash' => PlanningJson::hash($result), 'cost_log_id' => $log->id, 'created_at' => now()];
            if ($purpose === 'review') {
                ChapterReview::create([...$identity, 'verdict' => $result['verdict']]);
                if ($result['verdict'] !== 'pass') {
                    $run->update(['status' => 'failed', 'stop_reason' => 'REVIEW_NOT_PASSED']);

                    return false;
                }
                $run->update(['status' => 'pending', 'step' => 'extract', 'invocation_id' => (string) Str::uuid7()]);

                return true;
            }
            $extraction = ChapterEventExtraction::create([...$identity, 'verdict' => 'extracted']);
            $validation = $this->validateArtifacts($run, $extraction, []);
            $run->update(['status' => match ($validation->verdict) {
                'pass' => 'ready', 'requires_approval' => 'awaiting_approval', default => 'failed'
            },
                'stop_reason' => $validation->verdict === 'blocked' ? 'EVENT_VALIDATION_BLOCKED' : $validation->verdict]);

            return false;
        });
        if ($next && $dispatchNext) {
            $this->dispatch(ChapterSubmissionRun::findOrFail($id));
        }
    }

    /** 保存B05原文阅读回执；拒绝时只终止本运行，原始提取及验证结果保持不变。
     * @param  list<string>  $findings
     */
    public function recordEventReading(int $runId, int $extractionId, string $resultHash, User $operator, bool $accepted, array $findings): ChapterSubmissionRun
    {
        ContentSchema::listing($findings);
        if ($findings === []) {
            throw new InvalidArgumentException('READING_EVIDENCE_REQUIRED');
        }
        foreach ($findings as $finding) {
            ContentSchema::text($finding);
        }

        return DB::transaction(function () use ($runId, $extractionId, $resultHash, $operator, $accepted, $findings): ChapterSubmissionRun {
            $reference = ChapterSubmissionRun::findOrFail($runId);
            Novel::lockForUpdate()->findOrFail($reference->novel_id);
            $run = ChapterSubmissionRun::lockForUpdate()->findOrFail($runId);
            $extraction = ChapterEventExtraction::findOrFail($extractionId);
            if (! in_array($run->status, ['ready', 'awaiting_approval'], true) || $run->config['operator_id'] !== $operator->id
                || $extraction->submission_run_id !== $run->id || $extraction->result_hash !== $resultHash
                || PlanningJson::hash($extraction->result) !== $resultHash) {
                throw new InvalidArgumentException('EVENT_READING_SOURCE_INVALID');
            }
            $this->assertCurrent($run);
            $receipt = ['action' => 'event_reading', 'extraction_id' => $extractionId, 'result_hash' => $resultHash,
                'body_hash' => $run->body_hash, 'accepted' => $accepted, 'findings' => $findings, 'operator_id' => $operator->id, 'at' => now()->toIso8601String()];
            $run->update(['control_history' => [...$run->control_history, $receipt],
                'status' => $accepted ? $run->status : 'failed', 'stop_reason' => $accepted ? $run->stop_reason : 'EVENT_READING_REJECTED']);

            return $run->refresh();
        });
    }

    /** 冻结前驱的真实失败反馈；只提供纠错上下文，不能修改或放行旧产物。
     * @return array<string, mixed>
     */
    private function repairFeedback(ChapterSubmissionRun $predecessor): array
    {
        $extraction = ChapterEventExtraction::where('submission_run_id', $predecessor->id)->first();
        $validation = ChapterSubmissionValidation::where('submission_run_id', $predecessor->id)->latest('id')->first();
        if ($extraction === null || $validation === null) {
            // 中间评审失败不丢弃最近一次真实提取的纠错反馈，沿严格递减轮次回溯。
            if ($predecessor->predecessor_id !== null) {
                $ancestor = ChapterSubmissionRun::findOrFail($predecessor->predecessor_id);
                if ($ancestor->attempt >= $predecessor->attempt || PlanningJson::hash($ancestor->snapshot) !== PlanningJson::hash($predecessor->snapshot)) {
                    throw new InvalidArgumentException('RETRY_SOURCE_INVALID');
                }

                return $this->repairFeedback($ancestor);
            }

            return ['stop_reason' => $predecessor->stop_reason];
        }
        if ($extraction->result_hash !== PlanningJson::hash($extraction->result) || $validation->extraction_id !== $extraction->id
            || $extraction->version_id !== $predecessor->version_id || $extraction->body_hash !== $predecessor->body_hash) {
            throw new InvalidArgumentException('RETRY_ARTIFACT_STALE');
        }
        $result = $extraction->result;
        foreach (['events', 'proposed_entities'] as $field) {
            foreach ($result[$field] as &$item) {
                unset($item['evidence']);
            }
            unset($item);
        }

        $reading = array_values(array_filter($predecessor->control_history, fn (array $entry): bool => ($entry['action'] ?? null) === 'event_reading' && ($entry['accepted'] ?? true) === false));

        return ['extraction_id' => $extraction->id, 'result_hash' => $extraction->result_hash, 'validation_id' => $validation->id,
            'reading_findings' => $reading === [] ? [] : $reading[array_key_last($reading)]['findings'],
            'uncovered_evidence_ids' => array_values(array_diff(array_keys(ContentSchema::anchors($predecessor->version->body)), array_merge(...array_column($result['events'], 'evidence_ids')))),
            'validation_hash' => PlanningJson::hash($validation->result), 'rejected_result' => $result,
            'errors' => $validation->result['errors'], 'stop_reason' => $predecessor->stop_reason,
            'rules' => ['PATCH_SOURCE_INVALID' => '补丁entity_ref和value_ref必须出现在来源事件actor_ref或target_refs中。新地点必须在该事件target_refs中给完整定义，补丁重复使用相同定义。',
                'LOCATION_EVENT_REQUIRED' => '位置补丁只能引用location_change事件；静态观察不能产生位置变化补丁。',
                'PATCH_PRECONDITION_FAILED' => 'before_ref须与按事件顺序重放得到的原位置或原物主完全一致，未知才为空。',
                'UNKNOWN_ENTITY' => '既有实体使用目录编号，新实体每次引用给完整内联定义，禁止new-N字符串。']];
    }

    /** 同版本评审、提取和验证全部有摘要，不能只换编号借用其他章节产物。 */
    public function validateArtifacts(ChapterSubmissionRun $run, ChapterEventExtraction $extraction, array $approvedKeys): ChapterSubmissionValidation
    {
        $review = ChapterReview::where('submission_run_id', $run->id)->firstOrFail();
        foreach ([$review, $extraction] as $artifact) {
            if ($artifact->submission_run_id !== $run->id || $artifact->version_id !== $run->version_id || $artifact->body_hash !== $run->body_hash
                || $artifact->result_hash !== PlanningJson::hash($artifact->result)) {
                throw new InvalidArgumentException('ARTIFACT_STALE');
            }
        }
        foreach ([[$review, 'review'], [$extraction, 'extract']] as [$artifact, $purpose]) {
            $log = CostLog::find($artifact->cost_log_id);
            if ($log === null || $log->status !== 'succeeded' || $log->novel_id !== $run->novel_id
                || ($log->request_parameters['submission_run_id'] ?? null) !== $run->id
                || ($log->request_parameters['purpose'] ?? null) !== $purpose
                || ($log->request_parameters['body_hash'] ?? null) !== $run->body_hash) {
                throw new InvalidArgumentException('ARTIFACT_REQUEST_INVALID');
            }
            $raw = ContentSchema::decode($log->response_text);
            $normalized = $purpose === 'review' ? SubmissionContract::review($raw, $run->version->body) : app(SubmissionValidator::class)->normalize($raw, $run->version->body, $run->snapshot['state']);
            if (PlanningJson::hash($normalized) !== $artifact->result_hash) {
                throw new InvalidArgumentException('ARTIFACT_RESPONSE_CHANGED');
            }
        }
        if ($review->verdict !== 'pass' || $review->result['verdict'] !== 'pass') {
            throw new InvalidArgumentException('REVIEW_NOT_PASSED');
        }
        $result = app(SubmissionValidator::class)->validate($extraction->result, $run->snapshot['entity_catalog'], $run->snapshot['state'], $approvedKeys);

        return ChapterSubmissionValidation::create(['submission_run_id' => $run->id, 'review_id' => $review->id, 'extraction_id' => $extraction->id,
            'verdict' => $result['verdict'], 'input_hash' => PlanningJson::hash([$run->input_hash, $review->result_hash, $extraction->result_hash, $approvedKeys]), 'result' => $result, 'created_at' => now()]);
    }

    /** 专用队列只执行一次请求，不启用自动重试。 */
    public function dispatch(ChapterSubmissionRun $run): void
    {
        try {
            Bus::dispatch(new PrepareChapterSubmissionJob($run->id, $run->invocation_id));
        } catch (Throwable) {
            $this->interrupted($run->id, $run->invocation_id);
        }
    }

    /** 迟到失败回调不能覆盖成功产物；未确认结果永久保留费用记录。 */
    public function interrupted(int $id, string $token): void
    {
        DB::transaction(function () use ($id, $token): void {
            $run = ChapterSubmissionRun::lockForUpdate()->find($id);
            if ($run === null || $run->invocation_id !== $token || ! in_array($run->status, ['pending', 'running'], true)) {
                return;
            }
            $log = CostLog::where('request_parameters->submission_run_id', $run->id)->where('request_parameters->purpose', $run->step)->latest('id')->first();
            if ($log?->status === 'pending') {
                $log->update(['status' => 'unknown', 'error_category' => 'submission_interrupted', 'error_message' => '提交准备结果未知，未自动重发', 'finished_at' => now()]);
            }
            $run->update(['status' => 'failed', 'stop_reason' => $log === null ? 'DISPATCH_FAILED' : 'UNKNOWN_RESPONSE']);
        });
    }
}
