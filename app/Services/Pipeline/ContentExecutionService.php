<?php

namespace App\Services\Pipeline;

use App\Enums\ChapterStatus;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Jobs\RunChapterStepJob;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterProgressionCheck;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Models\User;
use App\Services\Ai\AiGenerationException;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\TextRequestOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/** 首次与恢复共用的有限执行器；一次消息最多一次HTTP，不自动创建后继。 */
final class ContentExecutionService
{
    /** 每次领用和回写均核对规划、版本、预算、细纲及受检正文。 */
    public function validate(ChapterGenerationRun $run): void
    {
        app(LegacyContentSources::class)->assertPlanningSources($run);
        $config = $run->config;
        $initial = $config['initial_step'] ?? null;
        $base = $initial === 'fine' ? 3 : 2;
        $cap = $base + ($initial === 'revise' ? 0 : 2 * ($config['max_retries'] ?? 0));
        if (($config['version'] ?? null) !== ContentExecutionContract::VERSION || $run->request_limit !== ($config['request_cap'] ?? null)
            || ! in_array($config['max_retries'] ?? null, [0, 1], true) || $run->retry_count > $config['max_retries']
            || ! in_array($initial, ['fine', 'draft', 'revise'], true) || ($initial === 'fine' && ! $config['enable_fine_outline'])
            || $run->requests_used < 0 || $run->requests_used > $run->request_limit || $run->request_limit < $base || $run->request_limit > $cap || $run->length_repair_count !== 0
            || data_get($config, 'authorization.scope') !== 'bounded_content') {
            throw new InvalidArgumentException('正文协议或冻结额度无效');
        }
        foreach (['fine', 'draft', 'revise', 'check'] as $step) {
            if (($config['protocol'][$step] ?? null) !== hash('sha256', ContentExecutionContract::instructions($step))) {
                throw new InvalidArgumentException('冻结指令已变化');
            }
        }
        if ($run->parent_run_id !== null) {
            $parent = ChapterGenerationRun::where('chapter_id', $run->chapter_id)->findOrFail($run->parent_run_id);
            $recovery = $run->snapshot['recovery'];
            if ($parent->input_hash !== $recovery['parent_input_hash'] || PlanningJson::hash([$parent->snapshot, $parent->config]) !== $parent->input_hash
                || $parent->status !== $recovery['parent_status'] || $parent->stop_reason !== $recovery['parent_stop_reason']) {
                throw new InvalidArgumentException('恢复父运行已变化');
            }
            if ($run->source_version_id !== null) {
                $source = ChapterVersion::where('chapter_id', $run->chapter_id)->findOrFail($run->source_version_id);
                if ($source->body_hash !== $recovery['source_body_hash'] || hash('sha256', $source->body) !== $source->body_hash
                    || PlanningJson::hash(app(LegacyContentSources::class)->sourceLineage($parent, $source)) !== PlanningJson::hash($recovery['source_lineage'])) {
                    throw new InvalidArgumentException('恢复正文祖先链已变化');
                }
            }
            if ($recovery['fine_stage_id'] !== null && ($run->fine_stage_id !== $recovery['fine_stage_id'] || PlanningJson::hash($run->plan_data) !== $recovery['plan_hash'])) {
                throw new InvalidArgumentException('复用细纲已变化');
            }
        }
        if ($run->config['enable_fine_outline'] && $run->plan_data !== null) {
            $fine = NovelStage::findOrFail($run->fine_stage_id);
            if ($fine->novel_id !== $run->chapter->novel_id || $fine->stage !== Stage::FineOutline || $fine->status !== StageStatus::Completed
                || PlanningJson::hash(data_get($fine->output, 'data.result')) !== PlanningJson::hash($run->plan_data)
                || ($run->parent_run_id === null && data_get($fine->input, 'run_id') !== $run->id)) {
                throw new InvalidArgumentException('细纲产物与来源不一致');
            }
            ContentSchema::fine($run->plan_data, array_column($run->snapshot['source_chapter']['progression_points'], 'id'));
        } elseif (! $run->config['enable_fine_outline'] && PlanningJson::hash($run->plan_data) !== PlanningJson::hash($run->snapshot['source_chapter'])) {
            throw new InvalidArgumentException('关闭细纲时必须直接沿用B03章计划');
        }
        $version = $this->candidate($run);
        $result = $run->chapter->progression_result;
        if ($result !== null) {
            $check = ChapterProgressionCheck::where('chapter_id', $run->chapter_id)->findOrFail($result['check_id']);
            if ($version === null || $check->version_id !== $version->id || $check->body_hash !== $version->body_hash
                || $result['version_id'] !== $version->id || $result['body_hash'] !== $version->body_hash
                || PlanningJson::hash($check->result) !== PlanningJson::hash(collect($result)->except(['check_id', 'version_id', 'body_hash'])->all())) {
                throw new InvalidArgumentException('检查与当前正文不一致');
            }
        }
    }

    /** 候选必须属于当前运行或经过祖先校验的明确源版本。 */
    private function candidate(ChapterGenerationRun $run): ?ChapterVersion
    {
        $id = $run->chapter->current_candidate_version_id;
        if ($id === null) {
            return null;
        }
        $version = ChapterVersion::where('chapter_id', $run->chapter_id)->findOrFail($id);
        if (($version->run_id !== $run->id && $version->id !== $run->source_version_id) || hash('sha256', $version->body) !== $version->body_hash || ContentSchema::count($version->body) !== $version->character_count) {
            throw new InvalidArgumentException('候选归属或摘要无效');
        }

        return $version;
    }

    /** 合并当前风险和已保存意见；规则提示不直接裁定语义错误。
     * @return array<string, array<string, mixed>>
     */
    public function findings(ChapterGenerationRun $run, ?ChapterVersion $version): array
    {
        $issues = $version === null ? [] : [...ContentQualityContract::issues($version->body, $run->snapshot['novel']['chapter_length_policy']), ...ContentReadingAudit::inspect($version->body)['issues']];
        foreach ($run->control_history as $control) {
            if (($control['action'] ?? null) === 'full_read_failed') {
                foreach ($control['notes'] as $note) {
                    $issues[] = ['type' => 'full_read', 'reason' => $note];
                }
            }
        }
        $result = $run->chapter->progression_result ?? [];
        foreach ([...($result['points'] ?? []), $result['hook'] ?? []] as $point) {
            if (($point['met'] ?? true) === false) {
                $issues[] = ['type' => 'progression', 'reason' => $point['reason']];
            }
        }
        foreach ([...($result['conflicts'] ?? []), ...($result['continuity_issues'] ?? [])] as $issue) {
            $issues[] = ['type' => 'check', 'reason' => $issue['reason']];
        }
        foreach ($result['reading_reviews'] ?? [] as $review) {
            if (! $review['fixed']) {
                $issues[] = ['type' => 'review', 'reason' => $review['reason']];
            }
        }
        $findings = $run->config['recovery_findings'] ?? [];
        foreach ($issues as $issue) {
            $issue['source_body_hash'] = $version?->body_hash;
            $findings['finding-'.substr(PlanningJson::hash($issue), 0, 16)] = $issue;
        }
        if ($findings === []) {
            $findings['whole-chapter'] = ['type' => 'whole_chapter', 'reason' => '核对完整推进、连贯时序与章末钩子'];
        }
        ksort($findings, SORT_STRING);

        return $findings;
    }

    /** 请求上下文由已冻结输入和不可变产物推导，不修改运行配置。
     * @return array{payload: array<string, mixed>, context: array<string, mixed>}
     */
    private function request(ChapterGenerationRun $run, ?ChapterVersion $version): array
    {
        $step = $run->next_step;
        $fine = $run->config['enable_fine_outline'];
        $budget = $step === 'fine' ? null : ContentExecutionContract::budget($run->snapshot['novel']['chapter_length_policy'], $run->plan_data, $fine);
        $context = ['anchors' => $version === null ? [] : ContentSchema::anchors($version->body), 'rules' => ContentQualityContract::rules($run->snapshot),
            'findings' => $this->findings($run, $version), 'budget' => $budget];
        $payload = ['author_decisions' => $run->snapshot['decisions'], 'confirmed_setting' => $run->snapshot['confirmed_setting'], 'candidate_roadmap' => $run->snapshot['roadmap'],
            'rule_catalog' => $context['rules'], 'chapter_plan' => $run->plan_data ?? $run->snapshot['source_chapter'], 'word_policy' => $run->snapshot['novel']['chapter_length_policy'],
            'progression_points' => $run->snapshot['source_chapter']['progression_points'], 'findings' => $context['findings'], 'count_rule' => ContentSchema::COUNT_VERSION];
        if ($budget !== null) {
            $payload['budget'] = $budget;
            $payload['slot_plan'] = [];
            foreach ($budget['slots'] as $index => $slot) {
                $payload['slot_plan'][$slot] = ['paragraph' => $index + 1, 'role' => $index === $budget['count'] - 1 ? '本章推进完成后停在冻结钩子的当下悬念' : '推进新的行动、信息及结果，不重演已经完成的动作'];
                if ($fine) {
                    $payload['slot_plan'][$slot] += $run->plan_data['scenes'][intdiv($index, 2)];
                }
            }
            $payload['effective_chapter_contract'] = ['progression_points' => $run->snapshot['source_chapter']['progression_points'], 'hook' => $run->plan_data['hook'], 'hook_transition' => $run->plan_data['hook_transition'] ?? null];
        }
        if (in_array($step, ['revise', 'check'], true)) {
            if ($version === null) {
                throw new InvalidArgumentException('修订或检查缺少当前正文');
            }
            $payload[$step === 'check' ? 'evidence_catalog' : 'available_anchors'] = $context['anchors'];
            $payload['body_hash'] = $version->body_hash;
            $policy = $run->snapshot['novel']['chapter_length_policy'];
            $payload['character_budget'] = ['current' => $version->character_count, 'minimum_change' => $policy['min_characters'] - $version->character_count,
                'maximum_change' => $policy['max_characters'] - $version->character_count, 'target_change' => intdiv($policy['min_characters'] + $policy['max_characters'], 2) - $version->character_count];
        }

        return ['payload' => $payload, 'context' => $context];
    }

    /** 单次领用先提交费用所需来源；HTTP绝不处于业务事务内。 */
    public function runStep(int $id, string $token, AiTextGenerationService $ai): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new InvalidArgumentException('正文请求不能处于外层事务');
        }
        $reference = ChapterGenerationRun::find($id);
        if ($reference === null) {
            return;
        }
        if (($reference->config['version'] ?? null) !== ContentExecutionContract::VERSION) {
            Log::notice('旧正文协议消息只读拒绝，须显式恢复为新协议', ['run_id' => $id]);

            return;
        }
        $claim = DB::transaction(function () use ($id, $token): ?array {
            $reference = ChapterGenerationRun::findOrFail($id);
            $novel = Novel::lockForUpdate()->findOrFail($reference->chapter->novel_id);
            $run = ChapterGenerationRun::lockForUpdate()->findOrFail($id);
            if ($run->status !== 'pending' || $run->invocation_id !== $token || $novel->status !== NovelStatus::Pending) {
                return null;
            }
            try {
                $this->validate($run);
                $version = $this->candidate($run);
                $request = $this->request($run, $version);
            } catch (Throwable) {
                $this->stop($run, $novel, 'invalid_contract');

                return null;
            }
            if ($run->requests_used >= $run->request_limit || $novel->execution_count >= $novel->max_execution_count) {
                $this->stop($run, $novel, 'execution_limit');

                return null;
            }
            if ($novel->stages()->where('status', 'running')->exists() || CostLog::where('novel_id', $novel->id)->where('status', 'pending')->exists()
                || ($run->next_step === 'revise' && ! $this->canRevise($run))) {
                $this->stop($run, $novel, 'retry_limit');

                return null;
            }
            $step = $run->next_step;
            $stageType = $step === 'fine' ? Stage::FineOutline : Stage::Content;
            $stage = NovelStage::create(['novel_id' => $novel->id, 'stage' => $stageType, 'status' => StageStatus::Running,
                'execution_count' => (int) $novel->stages()->where('stage', $stageType->value)->max('execution_count') + 1,
                'input' => ['pipeline' => ['version' => ContentExecutionContract::VERSION], 'run_id' => $run->id, 'step' => $step, 'invocation_id' => $token,
                    'input_hash' => $run->input_hash, 'version_id' => $version?->id, 'body_hash' => $version?->body_hash, 'request_ordinal' => $run->requests_used + 1,
                    'payload_hash' => PlanningJson::hash($request['payload'])], 'started_at' => now()]);
            $run->update(['status' => 'running', 'requests_used' => $run->requests_used + 1, 'retry_count' => $run->retry_count + ($step === 'revise' ? 1 : 0)]);
            $novel->update(['status' => NovelStatus::Running, 'current_stage' => $stageType, 'execution_count' => $novel->execution_count + 1]);
            $run->chapter->update(['status' => $step === 'check' ? ChapterStatus::Checking : ChapterStatus::Generating]);

            return [$run, $novel, $version, $stage, $request];
        });
        if ($claim === null) {
            return;
        }
        [$run, $novel, $source, $stage, $request] = $claim;
        $step = $run->next_step;
        $log = null;
        $data = null;
        $failure = null;
        $error = null;
        $started = hrtime(true);
        try {
            $options = TextRequestOptions::executionChapter($step, $run->config['temperature'], $request['context'], $run->config['max_output_tokens']);
            $log = $ai->generate('本次步骤 '.$step.'；冻结输入：'.PlanningJson::encode($request['payload']), 'real', $run->config['model'], $novel->id, $stage->id, 'b04-'.$step.'-prompt-v14', $options);
            if (preg_match('/^'.preg_quote($run->config['model'], '/').'(-\d{4}-\d{2}-\d{2})?$/D', $log->model ?? '') !== 1) {
                throw new InvalidArgumentException('响应模型与冻结模型不一致');
            }
            $response = ContentSchema::decode($log->response_text);
            $data = match ($step) {
                'fine' => ContentSchema::fine($response, array_column($run->snapshot['source_chapter']['progression_points'], 'id')),
                'draft' => ContentExecutionContract::body($response, $request['context']['budget']),
                'revise' => ContentSchema::body(['title' => $source->title, 'body' => ContentContinuationContract::revise($response, $source->body, $request['context']['findings'])]),
                'check' => ContentContinuationContract::check($response, $source->body, array_column($run->snapshot['source_chapter']['progression_points'], 'id'), $request['context']['rules'], $request['context']['findings']),
            };
            if ($step === 'fine') {
                ContentExecutionContract::budget($run->snapshot['novel']['chapter_length_policy'], $data, true);
            }
        } catch (AiGenerationException $exception) {
            $log = $exception->costLogId === null ? null : CostLog::find($exception->costLogId);
            $failure = $exception->status === 'unknown' ? 'unknown' : 'provider_failed';
        } catch (Throwable $exception) {
            $failure = $log?->status === 'succeeded' ? 'invalid_schema' : (CostLog::where('novel_stage_id', $stage->id)->exists() ? 'unknown' : 'audit_failed');
            $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : '本地处理异常，保留证据后显式恢复';
        }
        try {
            $next = DB::transaction(function () use ($id, $token, $stage, $source, $step, $data, $failure, $error, $log, $started): bool {
                $reference = ChapterGenerationRun::findOrFail($id);
                $novel = Novel::lockForUpdate()->findOrFail($reference->chapter->novel_id);
                $run = ChapterGenerationRun::lockForUpdate()->findOrFail($id);
                $stage = NovelStage::lockForUpdate()->findOrFail($stage->id);
                if ($run->status !== 'running' || $run->invocation_id !== $token || $stage->status !== StageStatus::Running) {
                    return false;
                }
                $this->validate($run);
                if ($this->candidate($run)?->id !== $source?->id) {
                    throw new InvalidArgumentException('请求期间候选发生变化');
                }
                $stop = $failure;
                $output = ['step' => $step, 'cost_log_id' => $log?->id, 'result' => $data, 'validation_error' => $error];
                if ($stop === null && $step === 'fine') {
                    $run->plan_data = $data;
                    $run->fine_stage_id = $stage->id;
                    $run->next_step = 'draft';
                } elseif ($stop === null && in_array($step, ['draft', 'revise'], true)) {
                    $version = ChapterVersion::create(['chapter_id' => $run->chapter_id, 'run_id' => $run->id, 'source_stage_id' => $stage->id, 'parent_version_id' => $source?->id,
                        'version' => (int) ChapterVersion::where('chapter_id', $run->chapter_id)->max('version') + 1, 'title' => $data['title'], 'body' => $data['body'],
                        'body_hash' => hash('sha256', $data['body']), 'character_count' => ContentSchema::count($data['body']), 'count_version' => ContentSchema::COUNT_VERSION, 'created_at' => now()]);
                    $run->chapter->update(['title' => $version->title, 'current_candidate_version_id' => $version->id, 'progression_result' => null, 'status' => ChapterStatus::Checking]);
                    $issues = ContentQualityContract::issues($version->body, $run->snapshot['novel']['chapter_length_policy']);
                    $output += ['version_id' => $version->id, 'quality_issues' => $issues, 'reading_audit' => ContentReadingAudit::inspect($version->body)];
                    if ($issues !== [] || $source?->body_hash === $version->body_hash) {
                        $run->next_step = 'revise';
                        $stop = $this->canRevise($run) ? null : 'body_quality_insufficient';
                    } else {
                        $run->next_step = 'check';
                    }
                } elseif ($stop === null) {
                    $check = ChapterProgressionCheck::create(['chapter_id' => $run->chapter_id, 'version_id' => $source->id, 'source_stage_id' => $stage->id,
                        'body_hash' => $source->body_hash, 'verdict' => $data['verdict'], 'result' => $data, 'created_at' => now()]);
                    $run->chapter->update(['progression_result' => ['check_id' => $check->id, 'version_id' => $source->id, 'body_hash' => $source->body_hash, ...$data],
                        'status' => $data['verdict'] === 'pass' ? ChapterStatus::Draft : ChapterStatus::RevisionRequired]);
                    $run->next_step = 'revise';
                    $stop = match ($data['verdict']) {
                        'pass' => 'awaiting_review', 'blocked' => 'blocked', default => $this->canRevise($run) ? null : 'retry_limit',
                    };
                    if ($stop === 'awaiting_review') {
                        $novel->progress = $run->config['enable_fine_outline'] ? '66.67' : '60.00';
                        $run->next_step = 'check';
                    }
                }
                $stage->update(['status' => $stop === null || $stop === 'awaiting_review' ? StageStatus::Completed : StageStatus::Failed,
                    'output' => ['simulation' => false, 'stage' => $stage->stage->value, 'data' => [...$output, 'outcome' => $stop]],
                    'error_message' => $stop === 'awaiting_review' ? null : $stop, 'cost' => $log?->only(['billing_status', 'currency', 'estimated_cost', 'actual_cost']),
                    'duration_ms' => (int) ((hrtime(true) - $started) / 1000000), 'finished_at' => now()]);
                $run->status = $stop === null ? 'pending' : ($stop === 'awaiting_review' ? 'completed' : 'paused');
                $run->stop_reason = $stop;
                $run->invocation_id = (string) Str::uuid7();
                $run->saveOrFail();
                $novel->status = $stop === null ? NovelStatus::Pending : NovelStatus::Paused;
                $novel->current_stage = Stage::Content;
                $novel->saveOrFail();

                return $stop === null;
            });
        } catch (Throwable) {
            $this->interrupted($id, $token);

            return;
        }
        if ($next) {
            $this->dispatch(ChapterGenerationRun::findOrFail($id));
        }
    }

    /** 所有修订共享同一运行计数，并为独立复查预留一次请求。 */
    public function canRevise(ChapterGenerationRun $run, bool $includeNovelLimit = true): bool
    {
        $id = $run->chapter->fresh()->current_candidate_version_id;
        $version = $id === null ? null : ChapterVersion::find($id);

        return $version !== null && count(ContentSchema::anchors($version->body)) >= 2
            && $run->retry_count < $run->config['max_retries'] && $run->request_limit - $run->requests_used >= 2
            && (! $includeNovelLimit || $run->chapter->novel->max_execution_count - $run->chapter->novel->execution_count >= 2);
    }

    /** 只记录全文结果；不通过不会投递模型请求或提高任何额度。 */
    public function recordReading(int $id, User $author, bool $accepted, array $notes): ChapterGenerationRun
    {
        if ($notes === []) {
            throw new InvalidArgumentException('全文核对必须填写结论');
        }
        foreach ($notes as $note) {
            ContentSchema::text($note);
        }

        return DB::transaction(function () use ($id, $author, $accepted, $notes): ChapterGenerationRun {
            $reference = ChapterGenerationRun::findOrFail($id);
            $novel = Novel::lockForUpdate()->findOrFail($reference->chapter->novel_id);
            $run = ChapterGenerationRun::lockForUpdate()->findOrFail($id);
            app(ContentGenerationService::class)->assertFrozenSources($run);
            $operator = $run->config['author_id'] ?? data_get($run->config, 'continuation_grant.operator_id') ?? data_get($run->control_history, '0.author_id');
            $result = $run->chapter->progression_result;
            if ($run->status !== 'completed' || $run->stop_reason !== 'awaiting_review' || $novel->status !== NovelStatus::Paused || $operator !== $author->id
                || $result === null || $result['verdict'] !== 'pass') {
                throw new InvalidArgumentException('没有可核对的当前候选或操作人不符');
            }
            $run->update(['status' => $accepted ? 'completed' : 'paused', 'stop_reason' => $accepted ? 'b04_accepted' : 'reading_failed',
                'control_history' => [...$run->control_history, ['action' => $accepted ? 'acceptance_complete' : 'full_read_failed', 'operator_id' => $author->id,
                    'version_id' => $result['version_id'], 'body_hash' => $result['body_hash'], 'check_id' => $result['check_id'], 'notes' => $notes,
                    'author_story_approval' => false, 'at' => now()->toIso8601String()]]]);

            return $run;
        });
    }

    /** 派发失败尚未发送HTTP，不能把零调用误标为未知付费。 */
    public function dispatch(ChapterGenerationRun $run): void
    {
        try {
            Bus::dispatch(new RunChapterStepJob($run->id, $run->invocation_id, true));
        } catch (Throwable) {
            $this->interrupted($run->id, $run->invocation_id);
        }
    }

    /** 同一令牌只结算一次；未知费用保留，迟到回调不覆盖新结果。 */
    public function interrupted(int $id, string $token): void
    {
        $reference = ChapterGenerationRun::find($id);
        if ($reference === null || ($reference->config['version'] ?? null) !== ContentExecutionContract::VERSION) {
            return;
        }
        DB::transaction(function () use ($id, $token): void {
            $run = ChapterGenerationRun::lockForUpdate()->findOrFail($id);
            $novel = Novel::lockForUpdate()->findOrFail($run->chapter->novel_id);
            if ($run->invocation_id !== $token || ! in_array($run->status, ['pending', 'running'], true)) {
                return;
            }
            $stage = NovelStage::where('novel_id', $novel->id)->where('status', 'running')->where('input->invocation_id', $token)->first();
            if ($stage !== null) {
                $log = CostLog::where('novel_stage_id', $stage->id)->latest('id')->first();
                if ($log?->status === 'pending') {
                    $log->update(['status' => 'unknown', 'error_category' => 'queue_interrupted', 'error_message' => '正文请求结果未知，未自动重发', 'finished_at' => now()]);
                }
                $stage->update(['status' => StageStatus::Failed, 'error_message' => 'unknown', 'output' => ['data' => ['outcome' => 'unknown', 'cost_log_id' => $log?->id]], 'finished_at' => now()]);
            }
            $this->stop($run, $novel, $stage === null ? 'dispatch_failed' : 'unknown');
        });
    }

    /** 暂停只影响候选生产状态，正式计数和故事状态始终保持。 */
    private function stop(ChapterGenerationRun $run, Novel $novel, string $reason): void
    {
        $run->update(['status' => 'paused', 'stop_reason' => $reason]);
        $novel->update(['status' => NovelStatus::Paused]);
    }
}
