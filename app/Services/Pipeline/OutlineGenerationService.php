<?php

namespace App\Services\Pipeline;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Jobs\RunOutlineBatchJob;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelOutlineChapter;
use App\Models\NovelOutlinePlan;
use App\Models\NovelSettingConfirmation;
use App\Models\NovelStage;
use App\Models\User;
use App\Services\Ai\TextRequestOptions;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** 固定范围大纲编排；沿用小说锁与次数额度，每批成功后才推进游标。 */
final class OutlineGenerationService
{
    /** 明确付费启动，输入只能引用同一本小说的有效确认版本。 */
    public function start(Novel $reference, int $confirmationId, User $author): NovelOutlinePlan
    {
        $this->outsideTransaction();
        $plan = DB::transaction(function () use ($reference, $confirmationId, $author): NovelOutlinePlan {
            $novel = Novel::query()->lockForUpdate()->findOrFail($reference->id);
            $confirmation = NovelSettingConfirmation::query()->where('novel_id', $novel->id)->findOrFail($confirmationId);
            $this->validConfirmation($novel, $confirmation);
            if ($novel->status !== NovelStatus::Paused || $novel->current_stage !== Stage::Outline || $novel->outlinePlans()->exists() || $novel->stages()->where('status', StageStatus::Running->value)->exists()) {
                $this->reject('小说不在可启动大纲的边界，或已有大纲计划。');
            }
            if ($novel->execution_count >= $novel->max_execution_count) {
                $this->reject('剩余执行额度不足，不能发送大纲请求。');
            }
            $settings = app(GeneralSettings::class);
            $model = trim($settings->default_model);
            if ($model === '' || strlen($model) > 128) {
                $this->reject('默认模型标识不合法。');
            }
            $config = TextRequestOptions::outline($settings->temperature, 1, min(10, $novel->target_chapters), $novel->target_chapters)->toArray();
            unset($config['range']);
            $token = (string) Str::uuid7();
            $plan = NovelOutlinePlan::query()->create([
                'novel_id' => $novel->id, 'setting_confirmation_id' => $confirmation->id, 'target_chapters' => $novel->target_chapters,
                'snapshot' => ['confirmation_hash' => $confirmation->content_hash, 'novel' => $novel->only(['id', 'title', 'genre', 'premise_raw', 'target_chapters', 'chapter_length_policy']), 'confirmed_setting' => $confirmation->setting, 'decisions' => $confirmation->decisions],
                'config' => [...$config, 'model' => $model, 'batch_size' => 10, 'prompt_version' => 'b03-outline-prompt-v1', 'enable_fine_outline' => data_get($novel->generation_config, 'enable_fine_outline')],
                'next_chapter' => 1, 'status' => 'pending', 'invocation_id' => $token,
                'control_history' => [$this->control('start', $author->id, $novel->max_execution_count, $novel->max_execution_count, '作者显式启动大纲')],
            ]);
            // 显式启动新阶段同时解除上一阶段的暂停意图。
            $novel->update(['status' => NovelStatus::Pending, 'pause_requested' => false]);

            return $plan;
        });
        $this->dispatch($plan->id, $plan->invocation_id);

        return $plan->refresh();
    }

    /** 显式续跑或重试；加额度必须有原因，未知费用必须明确确认。 */
    public function continue(int $planId, bool $confirmUnknown = false, ?int $maxExecutionCount = null, ?string $reason = null, ?int $authorId = null): NovelOutlinePlan
    {
        $this->outsideTransaction();
        $reference = NovelOutlinePlan::query()->findOrFail($planId);
        $plan = DB::transaction(function () use ($reference, $confirmUnknown, $maxExecutionCount, $reason, $authorId): NovelOutlinePlan {
            $novel = Novel::query()->lockForUpdate()->findOrFail($reference->novel_id);
            $plan = NovelOutlinePlan::query()->lockForUpdate()->findOrFail($reference->id);
            $this->validConfirmation($novel, $plan->confirmation);
            if (! in_array($plan->status, ['paused', 'error'], true) || $novel->stages()->where('status', StageStatus::Running->value)->exists()) {
                $this->reject('仅暂停或失败且没有活动尝试的计划允许继续。');
            }
            $uncertain = CostLog::query()->where('novel_id', $novel->id)->whereIn('status', ['unknown', 'pending'])->exists();
            $latest = $novel->stages()->where('stage', Stage::Outline->value)->latest('id')->first();
            if (! $confirmUnknown && ($uncertain || data_get($latest?->output, 'data.outcome') === 'unknown')) {
                $this->reject('上次结果未知，核查后使用 --confirm-unknown 接受可能重复计费。');
            }
            $oldLimit = $novel->max_execution_count;
            $newLimit = $maxExecutionCount ?? $oldLimit;
            if ($newLimit < $oldLimit || $newLimit > 2147483647 || $newLimit <= $novel->execution_count) {
                $this->reject('执行上限不能减少，且必须大于已执行次数。');
            }
            if ($newLimit !== $oldLimit && (! is_string($reason) || preg_match('/(*UCP)\S/u', $reason) !== 1)) {
                $this->reject('提高执行上限必须填写原因。');
            }
            $plan->update(['status' => 'pending', 'stop_reason' => null, 'invocation_id' => (string) Str::uuid7(),
                'control_history' => [...$plan->control_history, $this->control('continue', $authorId, $oldLimit, $newLimit, $reason ?? '显式继续现有大纲')]]);
            $novel->update(['status' => NovelStatus::Pending, 'pause_requested' => false, 'max_execution_count' => $newLimit]);

            return $plan;
        });
        $this->dispatch($plan->id, $plan->invocation_id);

        return $plan->refresh();
    }

    /** 单任务只领用一批，调用在事务外执行，成功后才投递下一批。 */
    public function runBatch(int $planId, string $token, OutlineExecutor $executor): void
    {
        $this->outsideTransaction();
        $reference = NovelOutlinePlan::query()->find($planId);
        if ($reference === null) {
            return;
        }
        $claim = DB::transaction(fn (): ?array => $this->claim($reference, $token));
        if ($claim === null) {
            return;
        }
        [$novel, $stage] = $claim;
        $started = hrtime(true);
        try {
            $result = $executor->execute($novel, $stage);
        } catch (Throwable) {
            $result = StageResult::failed('大纲结果无法确认，请核查阶段和费用；未自动重发。', ['outcome' => 'unknown']);
        }
        // 结算异常保留 running，交给任务失败回调按同一令牌转为未知结果。
        $nextToken = DB::transaction(fn (): ?string => $this->settle($reference, $token, $stage->id, $result, (int) ((hrtime(true) - $started) / 1000000)));
        if ($nextToken !== null) {
            $this->dispatch($planId, $nextToken);
        }
    }

    /** 领用短事务先锁小说再锁计划，其他入口遵守同样的锁顺序。
     * @return array{Novel, NovelStage}|null
     */
    private function claim(NovelOutlinePlan $reference, string $token): ?array
    {
        $novel = Novel::query()->lockForUpdate()->findOrFail($reference->novel_id);
        $plan = NovelOutlinePlan::query()->lockForUpdate()->findOrFail($reference->id);
        if ($plan->invocation_id !== $token || $plan->status !== 'pending' || $novel->status !== NovelStatus::Pending || $novel->stages()->where('status', StageStatus::Running->value)->exists()) {
            return null;
        }
        $this->validConfirmation($novel, $plan->confirmation);
        if ($novel->current_stage !== Stage::Outline || data_get($plan->snapshot, 'confirmation_hash') !== $plan->confirmation->content_hash || ! is_bool(data_get($plan->config, 'enable_fine_outline'))) {
            $this->reject('冻结输入与当前大纲边界不一致。');
        }
        $accepted = $plan->chapters()->selectRaw('count(*) as count, min(chapter_number) as first_number, max(chapter_number) as last_number')->first();
        if ((int) $accepted->count !== $plan->next_chapter - 1 || ($plan->next_chapter > 1 && ((int) $accepted->first_number !== 1 || (int) $accepted->last_number !== $plan->next_chapter - 1))) {
            $this->reject('已接纳章节不连续，拒绝覆盖或跳章。');
        }
        if ($novel->execution_count >= $novel->max_execution_count) {
            $plan->update(['status' => 'paused', 'stop_reason' => 'execution_limit']);
            $novel->update(['status' => NovelStatus::Paused]);

            return null;
        }
        $start = $plan->next_chapter;
        $end = min($start + 9, $plan->target_chapters);
        $previous = $novel->stages()->where('stage', Stage::Outline->value)->where('status', StageStatus::Completed->value)->where('input->outline_plan_id', $plan->id)->latest('id')->first();
        if ($start > 1 && ($previous === null || PlanningJson::hash($plan->handoff) !== PlanningJson::hash(data_get($previous->output, 'data.handoff')))) {
            $this->reject('前批延续摘要与成功记录不一致。');
        }
        if ($start > 1) {
            $first = $novel->stages()->where('stage', Stage::Outline->value)->where('status', StageStatus::Completed->value)->where('input->outline_plan_id', $plan->id)->oldest('id')->first();
            if (PlanningJson::hash($plan->roadmap) !== PlanningJson::hash(data_get($first?->output, 'data.roadmap'))) {
                $this->reject('冻结的全书走向已变化，拒绝继续。');
            }
        }
        $previousChapters = $plan->chapters()->where('chapter_number', '>=', max(1, $start - 10))->orderBy('chapter_number')->get()->map(fn (NovelOutlineChapter $chapter): array => $chapter->only(['chapter_number', 'title', 'summary', 'objective', 'conflict', 'hook', 'progression_points']))->all();
        $stage = $novel->stages()->create([
            'stage' => Stage::Outline, 'status' => StageStatus::Running,
            'execution_count' => (int) $novel->stages()->where('stage', Stage::Outline->value)->max('execution_count') + 1,
            'input' => [...$plan->snapshot, 'simulation' => false, 'pipeline' => ['version' => OutlineSchema::VERSION],
                'outline_plan_id' => $plan->id, 'invocation_id' => $token, 'generation_config' => $plan->config,
                'range' => ['start' => $start, 'end' => $end, 'total' => $plan->target_chapters],
                'roadmap' => $plan->roadmap, 'handoff' => $plan->handoff, 'previous_chapters' => $previousChapters,
                'previous_stage_id' => $previous?->id ?? $plan->confirmation->source_stage_id,
                'execution' => ['execution_count' => $novel->execution_count + 1, 'max_execution_count' => $novel->max_execution_count]],
            'started_at' => now(),
        ]);
        $novel->update(['status' => NovelStatus::Running, 'execution_count' => $novel->execution_count + 1]);
        $plan->update(['status' => 'running']);

        return [$novel, $stage];
    }

    /** 整批原子接纳，迟到结果不能覆盖已结束尝试或新令牌。 */
    private function settle(NovelOutlinePlan $reference, string $token, int $stageId, StageResult $result, int $durationMs): ?string
    {
        $novel = Novel::query()->lockForUpdate()->findOrFail($reference->novel_id);
        $plan = NovelOutlinePlan::query()->lockForUpdate()->findOrFail($reference->id);
        $stage = $novel->stages()->lockForUpdate()->findOrFail($stageId);
        if ($plan->invocation_id !== $token || $plan->status !== 'running' || $stage->status !== StageStatus::Running || data_get($stage->input, 'invocation_id') !== $token || $novel->status !== NovelStatus::Running || $novel->current_stage !== Stage::Outline) {
            return null;
        }
        if ($result->succeeded) {
            foreach ($result->output['chapters'] as $chapter) {
                NovelOutlineChapter::query()->create(['outline_plan_id' => $plan->id, 'source_stage_id' => $stage->id, ...$chapter]);
            }
            $plan->next_chapter = $stage->input['range']['end'] + 1;
            $plan->handoff = $result->output['handoff'];
            if ($plan->roadmap === null) {
                $plan->roadmap = $result->output['roadmap'];
            }
        }
        $stage->update(['status' => $result->succeeded ? StageStatus::Completed : StageStatus::Failed,
            'output' => ['simulation' => false, 'stage' => 'outline', 'data' => $result->output], 'error_message' => $result->error,
            'cost' => $result->cost, 'duration_ms' => $durationMs, 'finished_at' => now()]);
        if (! $result->succeeded) {
            $plan->status = $novel->execution_count >= $novel->max_execution_count ? 'paused' : 'error';
            $plan->stop_reason = $plan->status === 'paused' ? 'execution_limit' : 'batch_failed';
        } elseif ($plan->next_chapter > $plan->target_chapters) {
            $plan->status = 'completed';
            $plan->stop_reason = 'awaiting_next_stage';
            $novel->current_stage = $plan->config['enable_fine_outline'] ? Stage::FineOutline : Stage::Content;
        } elseif ($novel->pause_requested) {
            // 当前批结果已保存，只暂停尚未执行的下一批。
            $plan->status = 'paused';
            $plan->stop_reason = 'user_paused';
        } elseif ($novel->execution_count >= $novel->max_execution_count) {
            $plan->status = 'paused';
            $plan->stop_reason = 'execution_limit';
        } else {
            $plan->status = 'pending';
            $plan->stop_reason = null;
            $plan->invocation_id = (string) Str::uuid7();
        }
        $novel->status = match ($plan->status) {
            'pending' => NovelStatus::Pending, 'error' => NovelStatus::Error, default => NovelStatus::Paused
        };
        $stageCount = $plan->config['enable_fine_outline'] ? 6 : 5;
        $novel->progress = number_format((1 + ($plan->next_chapter - 1) / $plan->target_chapters) * 100 / $stageCount, 2, '.', '');
        $plan->saveOrFail();
        $novel->saveOrFail();

        return $plan->status === 'pending' ? $plan->invocation_id : null;
    }

    /** 队列中断只结算本令牌拥有的尝试，不重发、不冲销已知费用。 */
    public function interrupted(int $planId, string $token): void
    {
        $reference = NovelOutlinePlan::query()->find($planId);
        if ($reference === null) {
            return;
        }
        DB::transaction(function () use ($reference, $token): void {
            $novel = Novel::query()->lockForUpdate()->findOrFail($reference->novel_id);
            $plan = NovelOutlinePlan::query()->lockForUpdate()->findOrFail($reference->id);
            if ($plan->invocation_id !== $token || ! in_array($plan->status, ['pending', 'running'], true)) {
                return;
            }
            $stage = $novel->stages()->where('status', StageStatus::Running->value)->where('input->invocation_id', $token)->lockForUpdate()->first();
            if ($stage !== null) {
                $log = CostLog::query()->where('novel_stage_id', $stage->id)->latest('id')->first();
                if ($log?->status === 'pending') {
                    $log->update(['status' => 'unknown', 'error_category' => 'queue_interrupted', 'error_message' => '队列中断，结果未知；未自动重发。', 'finished_at' => now()]);
                }
                $stage->update(['status' => StageStatus::Failed, 'error_message' => '大纲任务中断，请核查费用后显式继续。',
                    'output' => ['simulation' => false, 'stage' => 'outline', 'data' => ['outcome' => 'unknown', 'cost_log_id' => $log?->id]], 'finished_at' => now()]);
            }
            $plan->update(['status' => $novel->execution_count >= $novel->max_execution_count ? 'paused' : 'error', 'stop_reason' => 'queue_interrupted']);
            $novel->update(['status' => $novel->execution_count >= $novel->max_execution_count ? NovelStatus::Paused : NovelStatus::Error]);
        });
    }

    /** 显式投递并捕获回执不确定性，只能标记尚未被消费者领用的同令牌计划。 */
    private function dispatch(int $planId, string $token): void
    {
        try {
            Bus::dispatch(new RunOutlineBatchJob($planId, $token));
        } catch (Throwable $exception) {
            $reference = NovelOutlinePlan::query()->findOrFail($planId);
            DB::transaction(function () use ($reference, $token): void {
                $novel = Novel::query()->lockForUpdate()->findOrFail($reference->novel_id);
                $plan = NovelOutlinePlan::query()->lockForUpdate()->findOrFail($reference->id);
                if ($plan->invocation_id === $token && $plan->status === 'pending') {
                    $plan->update(['status' => 'error', 'stop_reason' => 'dispatch_unconfirmed']);
                    $novel->update(['status' => NovelStatus::Error]);
                }
            });
            Log::warning('大纲投递未确认', ['plan_id' => $planId, 'error_category' => class_basename($exception)]);
        }
    }

    /** 校验同书来源、确认摘要及无正式剧情边界。 */
    private function validConfirmation(Novel $novel, NovelSettingConfirmation $confirmation): void
    {
        if ($novel->generation_mode !== GenerationMode::SettingReal || $confirmation->novel_id !== $novel->id || ! $confirmation->hasValidHash()
            || $novel->chapters_done !== 0 || $novel->state_version !== 0 || $novel->chapters()->exists()) {
            $this->reject('确认版本不属于该小说、内容已变化，或小说已有正式剧情。');
        }
        $source = (new ConfirmSettingService)->source($novel);
        if ($source->id !== $confirmation->source_stage_id || ! hash_equals($confirmation->source_hash, (new ConfirmSettingService)->sourceHash($source))) {
            $this->reject('确认版本的来源设定已变化。');
        }
    }

    /** 留下操作人、上限变化及原因，不把 CLI 操作伪装成后台用户确认。
     * @return array<string, mixed>
     */
    private function control(string $action, ?int $authorId, int $oldLimit, int $newLimit, string $reason): array
    {
        return ['action' => $action, 'author_id' => $authorId, 'source' => $authorId === null ? 'cli' : 'web', 'old_limit' => $oldLimit, 'new_limit' => $newLimit, 'reason' => $reason, 'at' => now()->toIso8601String()];
    }

    /** 业务拒绝通过验证错误返回，避免暴露数据库或供应商异常。 */
    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['outline' => $message]);
    }

    /** 投递和模型调用不能被外层事务包裹。 */
    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            $this->reject('请在数据库事务之外执行大纲操作。');
        }
    }
}
