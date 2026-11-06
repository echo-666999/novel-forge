<?php

namespace App\Services\Pipeline;

use App\Jobs\AdvanceFirstChapterJob;
use App\Jobs\RunSettingJob;
use App\Models\ChapterEventExtraction;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterReview;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterSubmissionValidation;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\FirstChapterRun;
use App\Models\Novel;
use App\Models\User;
use App\Services\Ai\AiGenerationException;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\TextRequestOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/** 新创意到授权范围逐章正式提交的持久化编排；每次领取至多发送一次模型请求。 */
final class FirstChapterService
{
    /** 创建时一次性显式授权，不能把已有小说悄悄接入自动提交。
     * @param  array<string, mixed>  $input
     */
    public function start(array $input, User $author, bool $authorized, bool $dispatch = true, ?string $model = null, int $chapterLimit = 1): FirstChapterRun
    {
        if (! in_array($chapterLimit, [1, 10], true) || ($chapterLimit === 10 && (int) ($input['target_chapters'] ?? 0) < 10)) {
            throw new InvalidArgumentException('连续生成要求至少十章规划，授权范围仅支持一章或十章');
        }
        if (! $authorized) {
            throw new InvalidArgumentException('必须明确授权设定决策、新实体采纳及指定章节范围正式提交');
        }
        // C01新授权默认使用用户指定的Terra；既有运行与首章默认模型不变。
        $model ??= $chapterLimit === 10 ? 'gpt-6.1-terra' : null;
        $novel = app(CreateNovelService::class)->createAndStart([...$input, 'generation_mode' => 'setting_real'], false, $model)->novel;
        $run = DB::transaction(function () use ($novel, $author, $chapterLimit): FirstChapterRun {
            // 总执行额度覆盖所有规划批次及授权章节，单个模型步骤仍有独立上限。
            $novel->update(['max_execution_count' => max($novel->max_execution_count, (int) ceil($novel->target_chapters / 10) + 20 * $chapterLimit)]);

            return FirstChapterRun::create(['novel_id' => $novel->id, 'author_id' => $author->id, 'chapter_limit' => $chapterLimit, 'policy' => FirstChapterRun::policy($chapterLimit === 10 ? 3 : 2),
                'policy_hash' => PlanningJson::hash(FirstChapterRun::policy($chapterLimit === 10 ? 3 : 2)), 'commit_key' => (string) Str::uuid7(), 'status' => 'pending', 'step' => 'setting',
                'artifacts' => [], 'history' => [['action' => 'authorized', 'operator_id' => $author->id, 'at' => now()->toIso8601String()]]]);
        });
        if ($dispatch) {
            $this->dispatch($run);
        }

        return $run->refresh();
    }

    /** 持久化独占领取；运行超时不会自动重发未知请求，须显式恢复。 */
    public function advance(int $id): FirstChapterRun
    {
        $token = (string) Str::uuid7();
        $claimed = FirstChapterRun::whereKey($id)->where('status', 'pending')->update(['status' => 'running', 'lease_token' => $token, 'lease_expires_at' => now()->addMinutes(5)]);
        $run = FirstChapterRun::findOrFail($id);
        if (! $claimed) {
            return $run;
        }
        try {
            $run->assertDelegation($run->novel_id, $run->author_id);
            $novel = Novel::findOrFail($run->novel_id);
            if ($novel->pause_requested) {
                throw new InvalidArgumentException('USER_PAUSED');
            }
            $step = $run->step;
            $number = $run->artifacts['chapter_number'] ?? 1;
            $this->perform($run, $novel, User::findOrFail($run->author_id));
            $run->history = [...$run->history, ['action' => 'advanced', 'chapter' => $number, 'step' => $step, 'next_step' => $run->step, 'requests' => $this->requestCount($run), 'at' => now()->toIso8601String()]];
            $run->status = $run->step === 'completed' ? 'completed' : 'pending';
            $run->error = null;
        } catch (Throwable $error) {
            $run->status = 'failed';
            // 供应商异常已由统一入口脱敏；其余只保存受控业务信息。
            $run->error = $error instanceof InvalidArgumentException || $error instanceof AiGenerationException ? $error->getMessage() : class_basename($error);
            $run->history = [...$run->history, ['action' => 'failed', 'step' => $run->step, 'error' => $run->error, 'requests' => $this->requestCount($run), 'at' => now()->toIso8601String()]];
            report($error);
        }
        $run->lease_token = null;
        $run->lease_expires_at = null;
        DB::transaction(function () use ($run, $token): void {
            $current = FirstChapterRun::lockForUpdate()->findOrFail($run->id);
            if ($current->lease_token !== $token || $current->status !== 'running') {
                throw new InvalidArgumentException('自动流程领取令牌已失效');
            }
            $run->saveOrFail();
        });

        return $this->recoverKnownFailure($run->refresh());
    }

    /** 已授权的新流程对明确失败自动创建后继；未知请求与重复错误仍保留断点。 */
    private function recoverKnownFailure(FirstChapterRun $run): FirstChapterRun
    {
        if ($run->status !== 'failed' || ($run->policy['automatic_recovery'] ?? null) !== 'distinct_failure_once'
            || CostLog::where('novel_id', $run->novel_id)->whereIn('status', ['unknown', 'pending'])->exists()) {
            return $run;
        }
        $recoverable = in_array($run->error, ['CONTENT_STOPPED:invalid_schema', 'CONTENT_STOPPED:provider_failed', 'CONTENT_STOPPED:retry_limit',
            'CONTENT_PARAGRAPH_INCOMPLETE', 'SUBMISSION_STOPPED:EVENT_VALIDATION_BLOCKED', 'SUBMISSION_STOPPED:INVALID_RESPONSE', 'SETTING_DECISION_FAILED'], true)
            || ($run->step === 'setting' && $run->error === 'ValidationException')
            || ($run->step === 'outline' && $run->error === 'OUTLINE_STOPPED:batch_failed');
        // 首章历史恢复指纹保持原值；十章授权按章区分失败，防止旧授权重复领用。
        $fingerprint = PlanningJson::hash($run->chapter_limit === 1 ? [$run->step, $run->error] : [$run->artifacts['chapter_number'] ?? 1, $run->step, $run->error]);
        if (! $recoverable || collect($run->history)->contains(fn (array $entry): bool => ($entry['automatic_failure_fingerprint'] ?? null) === $fingerprint)) {
            return $run;
        }
        // 先记录自动恢复原因；不静默覆盖失败记录或借重试批准未通过的独立评审。
        $run->update(['history' => [...$run->history, ['action' => 'automatic_recovery', 'automatic_failure_fingerprint' => $fingerprint,
            'reason' => $run->error, 'at' => now()->toIso8601String()]]]);
        try {
            return $this->retry($run->id, User::findOrFail($run->author_id));
        } catch (Throwable $error) {
            report($error);

            return $run->refresh();
        }
    }

    /** 顺序推进，产物先入库；断点重放优先复用已完成产物。
     * @throws InvalidArgumentException
     */
    private function perform(FirstChapterRun $run, Novel $novel, User $author): void
    {
        $artifacts = $run->artifacts;
        $number = $artifacts['chapter_number'] ?? 1;
        switch ($run->step) {
            case 'setting':
                $pipeline = new NovelPipelineService(new StageExecutorRegistry(app(SettingExecutor::class), true));
                $pipeline->run($novel, invocationId: $novel->generation_config['invocation_id']);
                $source = app(ConfirmSettingService::class)->source($novel->refresh());
                $artifacts['setting_stage_id'] = $source->id;
                $run->step = 'decision';
                break;
            case 'decision':
                $service = app(ConfirmSettingService::class);
                $draft = $service->draft($novel);
                if ($draft['answers'] !== []) {
                    // 成功响应可以重放解析；失败或未知响应需恢复时明确开启新尝试。
                    $log = CostLog::where('novel_id', $novel->id)->where('prompt_version', 'b10-setting-decision-v1')->latest('id')->first();
                    if ($log === null || ($artifacts['decision_retry_after'] ?? 0) >= $log->id) {
                        $log = app(AiTextGenerationService::class)->generate(PlanningJson::encode(['idea' => $novel->premise_raw, 'candidate' => $draft['setting'], 'policy' => $run->policy]),
                            'real', $novel->generation_config['model'], $novel->id, promptVersion: 'b10-setting-decision-v1', options: TextRequestOptions::decision());
                    }
                    if ($log->status !== 'succeeded') {
                        throw new InvalidArgumentException('SETTING_DECISION_FAILED');
                    }
                    $result = json_decode($log->response_text, true, flags: JSON_THROW_ON_ERROR);
                    if (! is_array($result['answers'] ?? null) || count($result['answers']) !== count($draft['answers'])) {
                        throw new InvalidArgumentException('SETTING_ANSWER_COUNT_MISMATCH');
                    }
                    foreach ($result['answers'] as $index => $answer) {
                        ContentSchema::text($answer);
                        $draft['answers'][$index]['answer'] = $answer;
                    }
                    $draft['setting'] = SettingSchema::parse(PlanningJson::encode($result['setting']));
                    $artifacts['decision_cost_log_id'] = $log->id;
                }
                $confirmation = $service->confirm($novel, $author, [...$draft, 'acknowledged' => true]);
                $artifacts['confirmation_id'] = $confirmation->id;
                $run->step = 'outline';
                break;
            case 'outline':
                $plan = $novel->outlinePlans()->latest('id')->first() ?? app(OutlineGenerationService::class)->start($novel, $artifacts['confirmation_id'], $author, $novel->generation_config['model']);
                $artifacts['outline_plan_id'] = $plan->id;
                if ($plan->status === 'pending') {
                    app(OutlineGenerationService::class)->runBatch($plan->id, $plan->invocation_id, app(OutlineExecutor::class));
                    $plan->refresh();
                }
                if ($plan->status === 'completed') {
                    $run->step = 'content';
                } elseif ($plan->status !== 'pending') {
                    throw new InvalidArgumentException('OUTLINE_STOPPED:'.$plan->stop_reason);
                }
                break;
            case 'content':
                $body = ChapterGenerationRun::whereHas('chapter', fn ($query) => $query->where('novel_id', $novel->id)->where('number', $number))->latest('id')->first()
                    ?? app(ContentGenerationService::class)->start($novel, $author, reason: '第'.$number.'章自动授权生成', modelOverride: $novel->generation_config['model'], chapterNumber: $number);
                $artifacts['body_run_id'] = $body->id;
                if ($body->status === 'pending') {
                    app(ContentExecutionService::class)->runStep($body->id, $body->invocation_id, app(AiTextGenerationService::class));
                    $body->refresh();
                }
                if ($body->stop_reason === 'awaiting_review') {
                    // 任何句中截断都必须先修复；独立模型通过不能豁免确定性完整性检查。
                    $version = ChapterVersion::findOrFail($body->chapter->current_candidate_version_id);
                    $incomplete = array_values(array_filter(ContentSchema::paragraphs($version->body), fn (array $paragraph): bool => ! ContentExecutionContract::hasCompleteEnding($paragraph['text'])));
                    if ($incomplete !== []) {
                        app(ContentExecutionService::class)->recordReading($body->id, $author, false,
                            ['自动段落完整性检查未通过：第'.implode('、', array_column($incomplete, 'paragraph')).'段在句中截断。必须由模型补全或合理合并，不能删除残句或仅补标点冒充完整；不是人工阅读结论。'], $run->id);
                        throw new InvalidArgumentException('CONTENT_PARAGRAPH_INCOMPLETE');
                    }
                    app(ContentExecutionService::class)->recordReading($body->id, $author, true, ['自动授权策略通过当前推进检查；后续独立评审通过后才提交。此记录不是人工全文阅读。'], $run->id);
                    $body->refresh();
                }
                if ($body->stop_reason === 'b04_accepted') {
                    $artifacts['version_id'] = $body->chapter->current_candidate_version_id;
                    $run->step = 'submission';
                } elseif ($body->status !== 'pending') {
                    throw new InvalidArgumentException('CONTENT_STOPPED:'.$body->stop_reason);
                }
                break;
            case 'submission':
                $submission = ChapterSubmissionRun::where('novel_id', $novel->id)->whereHas('chapter', fn ($query) => $query->where('number', $number))->latest('id')->first()
                    ?? app(ChapterSubmissionService::class)->prepare($artifacts['version_id'], $author, $number - 1, '第'.$number.'章自动授权评审与提取', dispatch: false, modelOverride: ChapterGenerationRun::findOrFail($artifacts['body_run_id'])->config['model']);
                $artifacts['submission_run_id'] = $submission->id;
                if ($submission->status === 'pending') {
                    app(ChapterSubmissionService::class)->execute($submission->id, $submission->invocation_id, false);
                    $submission->refresh();
                }
                if (in_array($submission->status, ['ready', 'awaiting_approval', 'committed'], true)) {
                    $run->step = 'commit';
                } elseif ($submission->status !== 'pending') {
                    throw new InvalidArgumentException('SUBMISSION_STOPPED:'.$submission->stop_reason);
                }
                break;
            case 'commit':
                $submission = ChapterSubmissionRun::findOrFail($artifacts['submission_run_id']);
                $review = ChapterReview::where('submission_run_id', $submission->id)->firstOrFail();
                $extraction = ChapterEventExtraction::where('submission_run_id', $submission->id)->firstOrFail();
                $validation = ChapterSubmissionValidation::where('submission_run_id', $submission->id)->orderBy('id')->firstOrFail();
                $commit = app(CanonicalCommitService::class)->commit($submission->version_id, $review->id, $extraction->id, $validation->id, $number - 1,
                    $run->chapterCommitKey($number), $author, $number === 1 ? '首章自动授权正式提交' : '第'.$number.'章自动授权正式提交', array_column($extraction->result['proposed_entities'], 'key'), true, $run->id);
                $artifacts['commit_id'] = $commit->id;
                // 正式提交完成才推进章号；提交回执与每章产物保留在编排历史中。
                $artifacts['chapters'][(string) $number] = ['number' => $number, 'body_run_id' => $artifacts['body_run_id'], 'version_id' => $artifacts['version_id'], 'submission_run_id' => $submission->id, 'commit_id' => $commit->id];
                if ($number < $run->chapter_limit) {
                    $artifacts['chapter_number'] = $number + 1;
                    unset($artifacts['body_run_id'], $artifacts['version_id'], $artifacts['submission_run_id'], $artifacts['commit_id']);
                    $run->step = 'content';
                } else {
                    $run->step = 'completed';
                }
                break;
            default:
                throw new InvalidArgumentException('UNKNOWN_AUTOMATION_STEP');
        }
        $run->artifacts = $artifacts;
    }

    /** 超时后显式核对各阶段中断，不自动发送任何模型请求。 */
    public function reconcile(int $id, User $author): void
    {
        // 全部为本地结算，事务失败时恢复原领取，避免遗留不可恢复的中间状态。
        DB::transaction(function () use ($id, $author): void {
            $run = FirstChapterRun::findOrFail($id);
            $run->assertDelegation($run->novel_id, $author->id);
            if ($run->status !== 'running' || $run->lease_expires_at === null || $run->lease_expires_at->isFuture()) {
                throw new InvalidArgumentException('领取尚未超时，不能接管运行中的请求');
            }
            // 先独占中断核对，防止两个恢复请求互相覆盖状态。
            if (! FirstChapterRun::whereKey($id)->where('status', 'running')->where('lease_token', $run->lease_token)->update(['status' => 'reconciling'])) {
                throw new InvalidArgumentException('已有其他请求正在核对中断');
            }
            $novel = Novel::findOrFail($run->novel_id);
            $number = $run->artifacts['chapter_number'] ?? 1;
            if ($run->step === 'setting') {
                (new RunSettingJob($novel->id, $novel->generation_config['invocation_id']))->failed(null);
            } elseif ($run->step === 'outline') {
                $plan = $novel->outlinePlans()->latest('id')->first();
                if ($plan?->status === 'running') {
                    app(OutlineGenerationService::class)->interrupted($plan->id, $plan->invocation_id);
                }
            } elseif ($run->step === 'content') {
                $body = ChapterGenerationRun::whereHas('chapter', fn ($query) => $query->where('novel_id', $novel->id)->where('number', $number))->latest('id')->first();
                if ($body?->status === 'running') {
                    app(ContentExecutionService::class)->interrupted($body->id, $body->invocation_id);
                }
            } elseif ($run->step === 'submission') {
                $submission = ChapterSubmissionRun::where('novel_id', $novel->id)->whereHas('chapter', fn ($query) => $query->where('number', $number))->latest('id')->first();
                if ($submission?->status === 'running') {
                    app(ChapterSubmissionService::class)->interrupted($submission->id, $submission->invocation_id);
                }
            }
            CostLog::where('novel_id', $novel->id)->where('status', 'pending')->update(['status' => 'unknown', 'error_category' => 'automation_interrupted', 'error_message' => '自动流程中断，结果未知，未自动重发', 'finished_at' => now()]);
            $run->update(['status' => 'failed', 'error' => 'INTERRUPTED_RECONCILED', 'lease_token' => null, 'lease_expires_at' => null,
                'history' => [...$run->history, ['action' => 'reconcile', 'operator_id' => $author->id, 'at' => now()->toIso8601String()]]]);
        });
    }

    /** 恢复原子领取，两个后台请求不能同时创建后继或修改运行状态。 */
    public function retry(int $id, User $author, bool $confirmUnknown = false, bool $rewrite = false, ?string $model = null): FirstChapterRun
    {
        $run = FirstChapterRun::findOrFail($id);
        $run->assertDelegation($run->novel_id, $author->id);
        if ($run->status === 'running') {
            $this->reconcile($id, $author);
        }
        if (! FirstChapterRun::whereKey($id)->where('status', 'failed')->update(['status' => 'recovering'])) {
            throw new InvalidArgumentException('只能恢复已停止的自动流程');
        }
        try {
            return $this->prepareRetry($id, $author, $confirmUnknown, $rewrite, $model);
        } catch (Throwable $error) {
            FirstChapterRun::whereKey($id)->where('status', 'recovering')->update(['status' => 'failed']);
            throw $error;
        }
    }

    /** 显式恢复保留所有旧证据；未知响应必须额外确认，不自动无限重放。 */
    private function prepareRetry(int $id, User $author, bool $confirmUnknown, bool $rewrite, ?string $model): FirstChapterRun
    {
        $run = FirstChapterRun::findOrFail($id);
        $run->assertDelegation($run->novel_id, $author->id);
        if ($run->status !== 'recovering') {
            throw new InvalidArgumentException('只能恢复已停止的自动流程');
        }
        if (! $confirmUnknown && CostLog::where('novel_id', $run->novel_id)->whereIn('status', ['pending', 'unknown'])->exists()) {
            throw new InvalidArgumentException('存在未知响应，必须明确确认保留费用风险后再恢复');
        }
        $novel = Novel::findOrFail($run->novel_id);
        $number = $run->artifacts['chapter_number'] ?? 1;
        // 连续十章初始额度较大，恢复不得以当前次数加二十反向降低已授权总额度。
        $recoveryLimit = max($novel->max_execution_count, $novel->execution_count + 20);
        $novel->update(['pause_requested' => false]);
        if ($run->step === 'setting' && ! $novel->stages()->where('status', 'completed')->exists()) {
            app(RetrySettingService::class)->retry($novel->id, $confirmUnknown, false);
        } elseif ($run->step === 'decision') {
            $run->artifacts = [...$run->artifacts, 'decision_retry_after' => CostLog::where('novel_id', $novel->id)->max('id') ?? 0];
        } elseif ($run->step === 'outline') {
            $plan = $novel->outlinePlans()->latest('id')->first();
            if ($plan !== null && in_array($plan->status, ['paused', 'error'], true)) {
                app(OutlineGenerationService::class)->continue($plan->id, $confirmUnknown, $recoveryLimit, '恢复自动首章', $author->id);
            }
        } elseif ($run->step === 'content') {
            $body = ChapterGenerationRun::whereHas('chapter', fn ($query) => $query->where('novel_id', $novel->id)->where('number', $number))->latest('id')->first();
            if ($body !== null && $body->stop_reason !== 'b04_accepted' && $body->status !== 'pending') {
                app(ContentGenerationService::class)->recover($body->id, $author, '恢复自动首章，保留前序失败证据', executionLimit: $recoveryLimit, confirmUnknown: $confirmUnknown, rewrite: $rewrite, model: $model);
            }
        } elseif ($run->step === 'submission') {
            $submission = ChapterSubmissionRun::where('novel_id', $novel->id)->whereHas('chapter', fn ($query) => $query->where('number', $number))->latest('id')->first();
            if ($submission?->status === 'failed') {
                app(ChapterSubmissionService::class)->prepare($submission->version_id, $author, $number - 1, '恢复自动首章提交准备', $recoveryLimit, false, $submission->id, $model ?? $submission->config['model']);
            }
        }
        $run->update(['status' => 'pending', 'error' => null, 'history' => [...$run->history, ['action' => 'retry', 'operator_id' => $author->id, 'confirm_unknown' => $confirmUnknown, 'rewrite' => $rewrite, 'model' => $model, 'at' => now()->toIso8601String()]]]);

        return $run;
    }

    /** 请求计数取小说独立成本流水，失败和未知请求同样计入。 */
    public function requestCount(FirstChapterRun $run): int
    {
        return CostLog::where('novel_id', $run->novel_id)->where('mode', 'real')->count();
    }

    /** 投递失败可从相同断点重试，不重复创建小说。 */
    public function dispatch(FirstChapterRun $run): void
    {
        try {
            Bus::dispatch(new AdvanceFirstChapterJob($run->id));
        } catch (Throwable) {
            FirstChapterRun::whereKey($run->id)->where('status', 'pending')->update(['status' => 'failed', 'error' => 'DISPATCH_FAILED']);
        }
    }
}
