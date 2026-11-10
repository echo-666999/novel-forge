<?php

namespace App\Services\Pipeline;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\ProductionFailure;
use App\Jobs\AdvanceProductionRunJob;
use App\Jobs\RunSettingJob;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\ProductionRun;
use App\Models\User;
use App\Services\Ai\AiGenerationException;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\ModelProfiles;
use App\Services\Ai\TextRequestOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/** 章节范围生产运行的持久化编排：新书从设定到授权范围逐章正式提交，已有书可续写下一段章节。每次领取至多发送一次模型请求。 */
final class ProductionRunService
{
    /** 全书规划步骤；章节步骤由 ChapterProductionStepper 负责。 */
    public const PLANNING_STEPS = ['setting', 'decision', 'outline'];

    public function __construct(private ChapterProductionStepper $stepper) {}

    /** 创建时一次性显式授权，不能把已有小说悄悄接入自动提交。
     * @param  array<string, mixed>  $input
     */
    public function start(array $input, User $author, bool $authorized, bool $dispatch = true, ?string $model = null, int $chapterLimit = 1): ProductionRun
    {
        if (! in_array($chapterLimit, [1, 10], true) || ($chapterLimit === 10 && (int) ($input['target_chapters'] ?? 0) < 10)) {
            throw new InvalidArgumentException('连续生成要求至少十章规划，授权范围仅支持一章或十章');
        }
        if (! $authorized) {
            throw new InvalidArgumentException('必须明确授权设定决策、新实体采纳及指定章节范围正式提交');
        }
        // 新运行统一使用通用设置的默认模型；显式覆盖及既有冻结运行保持不变。
        $novel = app(CreateNovelService::class)->createAndStart([...$input, 'generation_mode' => 'setting_real'], false, $model)->novel;
        $run = DB::transaction(function () use ($novel, $author, $chapterLimit): ProductionRun {
            // 总执行额度覆盖所有规划批次及授权章节，单个模型步骤仍有独立上限。
            $novel->update(['max_execution_count' => max($novel->max_execution_count, (int) ceil($novel->target_chapters / 10) + 20 * $chapterLimit)]);

            return ProductionRun::create(['novel_id' => $novel->id, 'author_id' => $author->id, 'start_chapter' => 1, 'chapter_limit' => $chapterLimit, 'policy' => ProductionRun::policy($chapterLimit === 10 ? 3 : 2),
                'policy_hash' => PlanningJson::hash(ProductionRun::policy($chapterLimit === 10 ? 3 : 2)), 'commit_key' => (string) Str::uuid7(), 'status' => 'pending', 'step' => 'setting',
                'artifacts' => [], 'history' => [['action' => 'authorized', 'operator_id' => $author->id, 'at' => now()->toIso8601String()]]]);
        });
        if ($dispatch) {
            $this->dispatch($run);
        }

        return $run->refresh();
    }

    /** 续写授权：从下一章起最多授权十章，冻结范围与模型。要求前序运行全部完成、大纲覆盖范围且没有在途章节。 */
    public function extend(int $novelId, User $author, int $count, ?string $model, string $reason, bool $dispatch = true): ProductionRun
    {
        ContentSchema::text($reason);
        if ($count < 1 || $count > ProductionRun::MAX_RANGE) {
            throw new InvalidArgumentException('单次续写授权为1至'.ProductionRun::MAX_RANGE.'章');
        }
        if ($model !== null && ! array_key_exists($model, ModelProfiles::options())) {
            throw new InvalidArgumentException('模型不在可选模型列表中');
        }
        $run = DB::transaction(function () use ($novelId, $author, $count, $model, $reason): ProductionRun {
            $novel = Novel::lockForUpdate()->findOrFail($novelId);
            $start = $novel->chapters_done + 1;
            $end = $start + $count - 1;
            $plan = $novel->outlinePlans()->where('status', 'completed')->latest('id')->first();
            if ($novel->generation_mode !== GenerationMode::SettingReal || $novel->archived_at !== null) {
                throw new InvalidArgumentException('仅未归档的真实生成小说可以续写');
            }
            if ($novel->status !== NovelStatus::Paused || $novel->pause_requested || $novel->state_version !== $novel->chapters_done) {
                throw new InvalidArgumentException('小说当前不在可续写的暂停边界，请先处理在途运行');
            }
            if ($novel->chapters_done < 1 || $end > $novel->target_chapters || $plan === null || $plan->chapters()->whereBetween('chapter_number', [$start, $end])->count() !== $count) {
                throw new InvalidArgumentException('续写范围必须在目标章数和已完成大纲之内，且已至少正式提交一章');
            }
            if (ProductionRun::where('novel_id', $novel->id)->where('status', '!=', 'completed')->exists()) {
                throw new InvalidArgumentException('本书还有未完成的生产运行，请先恢复或完成它');
            }
            if ($novel->chapters()->where('number', '>=', $start)->exists()) {
                throw new InvalidArgumentException('第'.$start.'章已有候选，请先在正文工作台处理后再授权续写');
            }
            $predecessor = ProductionRun::latestFor($novel->id);
            // 执行额度按章预留，与十章授权的计算方式一致；不降低已有额度。
            $novel->update(['max_execution_count' => max($novel->max_execution_count, $novel->execution_count + 20 * $count)]);
            $policy = ProductionRun::rangePolicy($start, $end, $model);

            return ProductionRun::create(['novel_id' => $novel->id, 'author_id' => $author->id, 'start_chapter' => $start, 'chapter_limit' => $end, 'predecessor_id' => $predecessor?->id,
                'policy' => $policy, 'policy_hash' => PlanningJson::hash($policy), 'commit_key' => (string) Str::uuid7(), 'status' => 'pending', 'step' => 'content',
                'artifacts' => ['chapter_number' => $start, 'cost_log_baseline' => (int) (CostLog::where('novel_id', $novel->id)->max('id') ?? 0)],
                'history' => [['action' => 'authorized', 'operator_id' => $author->id, 'range' => [$start, $end], 'model' => $model, 'reason' => $reason, 'at' => now()->toIso8601String()]]]);
        });
        if ($dispatch) {
            $this->dispatch($run);
        }

        return $run->refresh();
    }

    /** 持久化独占领取；运行超时不会自动重发未知请求，须显式恢复。 */
    public function advance(int $id): ProductionRun
    {
        $token = (string) Str::uuid7();
        $claimed = ProductionRun::whereKey($id)->where('status', 'pending')->update(['status' => 'running', 'lease_token' => $token, 'lease_expires_at' => now()->addMinutes(5)]);
        $run = ProductionRun::findOrFail($id);
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
            $number = $run->artifacts['chapter_number'] ?? $run->start_chapter;
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
            $current = ProductionRun::lockForUpdate()->findOrFail($run->id);
            if ($current->lease_token !== $token || $current->status !== 'running') {
                throw new InvalidArgumentException('自动流程领取令牌已失效');
            }
            $run->saveOrFail();
        });

        return $this->recoverKnownFailure($run->refresh());
    }

    /** 已授权的流程对明确失败自动创建后继；未知请求与重复错误仍保留断点。 */
    private function recoverKnownFailure(ProductionRun $run): ProductionRun
    {
        if ($run->status !== 'failed' || ($run->policy['automatic_recovery'] ?? null) !== 'distinct_failure_once'
            || CostLog::where('novel_id', $run->novel_id)->where(fn ($query) => $query->where('status', 'pending')->orWhere(fn ($unknown) => $unknown->where('status', 'unknown')->whereNotIn('id', $run->acknowledgedUnknownLogIds())))->exists()) {
            return $run;
        }
        // 首章历史恢复指纹保持原值；多章授权按章区分失败，防止旧授权重复领用。
        $fingerprint = PlanningJson::hash($run->chapter_limit === 1 ? [$run->step, $run->error] : [$run->artifacts['chapter_number'] ?? $run->start_chapter, $run->step, $run->error]);
        if (! ProductionFailure::isAutomaticallyRecoverable($run->error, $run->step) || collect($run->history)->contains(fn (array $entry): bool => ($entry['automatic_failure_fingerprint'] ?? null) === $fingerprint)) {
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

    /** 顺序推进，产物先入库；断点重放优先复用已完成产物。规划步骤在此处理，章节步骤交给步骤机。
     * @throws InvalidArgumentException
     */
    private function perform(ProductionRun $run, Novel $novel, User $author): void
    {
        $artifacts = $run->artifacts;
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
            default:
                $artifacts = $this->stepper->perform($run, $novel, $author, $artifacts);
        }
        $run->artifacts = $artifacts;
    }

    /** 超时后显式核对各阶段中断，不自动发送任何模型请求。 */
    public function reconcile(int $id, User $author): void
    {
        // 全部为本地结算，事务失败时恢复原领取，避免遗留不可恢复的中间状态。
        DB::transaction(function () use ($id, $author): void {
            $run = ProductionRun::findOrFail($id);
            $run->assertDelegation($run->novel_id, $author->id);
            if ($run->status !== 'running' || $run->lease_expires_at === null || $run->lease_expires_at->isFuture()) {
                throw new InvalidArgumentException('领取尚未超时，不能接管运行中的请求');
            }
            // 先独占中断核对，防止两个恢复请求互相覆盖状态。
            if (! ProductionRun::whereKey($id)->where('status', 'running')->where('lease_token', $run->lease_token)->update(['status' => 'reconciling'])) {
                throw new InvalidArgumentException('已有其他请求正在核对中断');
            }
            $novel = Novel::findOrFail($run->novel_id);
            if ($run->step === 'setting') {
                (new RunSettingJob($novel->id, $novel->generation_config['invocation_id']))->failed(null);
            } elseif ($run->step === 'outline') {
                $plan = $novel->outlinePlans()->latest('id')->first();
                if ($plan?->status === 'running') {
                    app(OutlineGenerationService::class)->interrupted($plan->id, $plan->invocation_id);
                }
            } else {
                $this->stepper->interrupt($run, $novel);
            }
            CostLog::where('novel_id', $novel->id)->where('status', 'pending')->update(['status' => 'unknown', 'error_category' => 'automation_interrupted', 'error_message' => '自动流程中断，结果未知，未自动重发', 'finished_at' => now()]);
            $run->update(['status' => 'failed', 'error' => 'INTERRUPTED_RECONCILED', 'lease_token' => null, 'lease_expires_at' => null,
                'history' => [...$run->history, ['action' => 'reconcile', 'operator_id' => $author->id, 'at' => now()->toIso8601String()]]]);
        });
    }

    /** 恢复原子领取，两个后台请求不能同时创建后继或修改运行状态。 */
    public function retry(int $id, User $author, bool $confirmUnknown = false, bool $rewrite = false, ?string $model = null): ProductionRun
    {
        $run = ProductionRun::findOrFail($id);
        $run->assertDelegation($run->novel_id, $author->id);
        if ($run->status === 'running') {
            $this->reconcile($id, $author);
        }
        if (! ProductionRun::whereKey($id)->where('status', 'failed')->update(['status' => 'recovering'])) {
            throw new InvalidArgumentException('只能恢复已停止的自动流程');
        }
        try {
            return $this->prepareRetry($id, $author, $confirmUnknown, $rewrite, $model);
        } catch (Throwable $error) {
            ProductionRun::whereKey($id)->where('status', 'recovering')->update(['status' => 'failed']);
            throw $error;
        }
    }

    /** 显式恢复保留所有旧证据；未知响应必须额外确认，不自动无限重放。 */
    private function prepareRetry(int $id, User $author, bool $confirmUnknown, bool $rewrite, ?string $model): ProductionRun
    {
        $run = ProductionRun::findOrFail($id);
        $run->assertDelegation($run->novel_id, $author->id);
        if ($run->status !== 'recovering') {
            throw new InvalidArgumentException('只能恢复已停止的自动流程');
        }
        if (! $confirmUnknown && CostLog::where('novel_id', $run->novel_id)->where(fn ($query) => $query->where('status', 'pending')->orWhere(fn ($unknown) => $unknown->where('status', 'unknown')->whereNotIn('id', $run->acknowledgedUnknownLogIds())))->exists()) {
            throw new InvalidArgumentException('存在未知响应，必须明确确认保留费用风险后再恢复');
        }
        $novel = Novel::findOrFail($run->novel_id);
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
                app(OutlineGenerationService::class)->continue($plan->id, $confirmUnknown, $recoveryLimit, '恢复自动生成', $author->id);
            }
        } elseif (in_array($run->step, ChapterProductionStepper::STEPS, true)) {
            $this->stepper->prepareRetry($run, $novel, $author, $recoveryLimit, $confirmUnknown, $rewrite, $model);
        }
        $run->update(['status' => 'pending', 'error' => null, 'history' => [...$run->history, ['action' => 'retry', 'operator_id' => $author->id, 'confirm_unknown' => $confirmUnknown, 'confirmed_unknown_log_ids' => $confirmUnknown ? CostLog::where('novel_id', $novel->id)->where('status', 'unknown')->pluck('id')->all() : [], 'rewrite' => $rewrite, 'model' => $model, 'at' => now()->toIso8601String()]]]);

        return $run;
    }

    /** 本次运行的真实请求数，失败和未知请求同样计入；续写运行只统计授权之后的请求。 */
    public function requestCount(ProductionRun $run): int
    {
        return CostLog::where('novel_id', $run->novel_id)->where('mode', 'real')->where('id', '>', (int) ($run->artifacts['cost_log_baseline'] ?? 0))->count();
    }

    /** 投递失败可从相同断点重试，不重复创建小说。 */
    public function dispatch(ProductionRun $run): void
    {
        try {
            Bus::dispatch(new AdvanceProductionRunJob($run->id));
        } catch (Throwable) {
            ProductionRun::whereKey($run->id)->where('status', 'pending')->update(['status' => 'failed', 'error' => 'DISPATCH_FAILED']);
        }
    }
}
