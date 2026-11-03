<?php

namespace App\Services\Pipeline;

use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Settings\GeneralSettings;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** A05 同步模拟流水线；领用与结算短事务之间不持有数据库锁。 */
final class NovelPipelineService
{
    /** 注册表只负责固定阶段的执行器解析。 */
    public function __construct(private StageExecutorRegistry $executors) {}

    /** 启动或继续可运行状态；细纲参数只在第一次实际领用阶段时生效。 */
    public function run(Novel $novel, ?bool $enableFineOutline = null): PipelineResult
    {
        $this->assertOutsideTransaction($novel);
        // 固定阶段无环，单次调用最多领用六次；失败与暂停立即返回。
        foreach (Stage::cases() as $unused) {
            $claim = $novel->getConnection()->transaction(fn (): array|PipelineResult => $this->claim($novel, $enableFineOutline));
            if ($claim instanceof PipelineResult) {
                return $claim;
            }
            [$snapshot, $stage] = $claim;
            try {
                $result = $this->executors->forStage($stage->stage)->execute($snapshot, $stage);
            } catch (Throwable $exception) {
                // 不保存异常消息或堆栈，避免故障执行器把认证信息带入记录。
                $result = StageResult::failed('模拟阶段执行异常：'.class_basename($exception));
            }
            $settled = $novel->getConnection()->transaction(fn (): PipelineResult => $this->settle($novel, $stage->id, $result));
            if ($settled->reason !== 'advanced') {
                return $settled;
            }
        }

        throw new LogicException('固定阶段调用超过六次，拒绝继续执行');
    }

    /** 明确恢复暂停或错误状态；保留历史次数和已完成阶段，额度不足不恢复。 */
    public function resume(Novel $novel): PipelineResult
    {
        $this->assertOutsideTransaction($novel);
        $stopped = $novel->getConnection()->transaction(function () use ($novel): ?PipelineResult {
            $locked = $this->lockedNovel($novel);
            if ($locked->status === NovelStatus::Completed) {
                return PipelineResult::fromNovel($locked, 'already_completed');
            }
            if (! in_array($locked->status, [NovelStatus::Paused, NovelStatus::Error], true)) {
                throw new InvalidArgumentException('仅暂停或错误状态支持显式恢复');
            }
            $this->plan($locked, null);
            if ($locked->stages()->where('status', StageStatus::Running->value)->exists()) {
                return PipelineResult::fromNovel($locked, 'busy');
            }
            if ($locked->execution_count >= $locked->max_execution_count) {
                $locked->update(['status' => NovelStatus::Paused]);

                return PipelineResult::fromNovel($locked, 'execution_limit');
            }
            $locked->update(['status' => NovelStatus::Running]);

            return null;
        });

        return $stopped ?? $this->run($novel);
    }

    /** 原子领用下一阶段：只有成功取得额度后才创建 running 尝试。
     * @return array{Novel, NovelStage}|PipelineResult
     */
    private function claim(Novel $reference, ?bool $fineOutline): array|PipelineResult
    {
        $novel = $this->lockedNovel($reference);
        if ($novel->status === NovelStatus::Completed) {
            return PipelineResult::fromNovel($novel, 'already_completed');
        }
        if (in_array($novel->status, [NovelStatus::Paused, NovelStatus::Error], true)) {
            return PipelineResult::fromNovel($novel, $novel->execution_count >= $novel->max_execution_count ? 'execution_limit' : 'resume_required');
        }
        if ($novel->stages()->where('status', StageStatus::Running->value)->exists()) {
            return PipelineResult::fromNovel($novel, 'busy');
        }
        [$plan, $frozen] = $this->plan($novel, $fineOutline);
        $completed = $novel->stages()->where('status', StageStatus::Completed->value)->orderBy('id')->get();
        $values = $completed->map(static fn (NovelStage $stage): Stage => $stage->stage)->all();
        if ($values !== array_slice($plan, 0, count($values)) || count($values) >= count($plan)) {
            throw new LogicException('阶段成功历史不是固定顺序的连续前缀，拒绝推进');
        }
        $next = $plan[count($values)];
        if ($novel->current_stage !== null && $novel->current_stage !== $next) {
            throw new LogicException('当前阶段与已完成历史不一致，拒绝跳跃执行');
        }
        if ($novel->execution_count >= $novel->max_execution_count) {
            $novel->update(['current_stage' => $next, 'status' => NovelStatus::Paused]);

            return PipelineResult::fromNovel($novel, 'execution_limit');
        }
        $previous = $completed->last();
        $ordinal = (int) $novel->stages()->where('stage', $next->value)->max('execution_count') + 1;
        $stage = $novel->stages()->create([
            'stage' => $next, 'status' => StageStatus::Running, 'execution_count' => $ordinal,
            'input' => ['simulation' => true, 'pipeline' => ['version' => 'a05-v1', 'enable_fine_outline' => $frozen], 'previous_stage_id' => $previous?->id, 'previous_output' => $previous?->output],
            'started_at' => now(),
        ]);
        $novel->update(['status' => NovelStatus::Running, 'current_stage' => $next, 'execution_count' => $novel->execution_count + 1]);

        return [$novel, $stage];
    }

    /** 结算当前领用结果；已结束或失去归属的尝试不允许被迟到结果覆盖。 */
    private function settle(Novel $reference, int $stageId, StageResult $result): PipelineResult
    {
        $novel = $this->lockedNovel($reference);
        $stage = $novel->stages()->whereKey($stageId)->lockForUpdate()->firstOrFail();
        $active = $novel->stages()->where('status', StageStatus::Running->value)->orderByDesc('id')->value('id');
        if ($stage->status !== StageStatus::Running || $novel->status !== NovelStatus::Running || $novel->current_stage !== $stage->stage || (int) $active !== $stageId) {
            return PipelineResult::fromNovel($novel, 'stale_result');
        }
        [$plan] = $this->plan($novel, null);
        $index = array_search($stage->stage, $plan, true);
        if ($index === false) {
            throw new LogicException('当前尝试不在冻结阶段集合中');
        }
        $stage->update([
            'status' => $result->succeeded ? StageStatus::Completed : StageStatus::Failed,
            'output' => ['simulation' => true, 'stage' => $stage->stage->value, 'data' => $result->output],
            'error_message' => $result->error !== null ? mb_substr($result->error, 0, 2000) : null,
            'finished_at' => now(),
        ]);
        if ($result->succeeded) {
            $novel->progress = number_format(($index + 1) * 100 / count($plan), 2, '.', '');
            // 最后一个允许的尝试完成全链时，完成优先于额度暂停。
            if ($index === count($plan) - 1) {
                $novel->status = NovelStatus::Completed;
                $novel->save();

                return PipelineResult::fromNovel($novel, 'completed');
            }
            $novel->current_stage = $plan[$index + 1];
        }
        if ($novel->execution_count >= $novel->max_execution_count) {
            $novel->status = NovelStatus::Paused;
            $reason = 'execution_limit';
        } else {
            $novel->status = $result->succeeded ? NovelStatus::Running : NovelStatus::Error;
            $reason = $result->succeeded ? 'advanced' : 'failed';
        }
        $novel->save();

        return PipelineResult::fromNovel($novel, $reason);
    }

    /** 从首条尝试读取冻结开关；只允许本服务产生的模拟历史。
     * @return array{list<Stage>, bool}
     */
    private function plan(Novel $novel, ?bool $requested): array
    {
        if ($novel->chapters_done !== 0 || $novel->state_version !== 0 || $novel->chapters()->exists()) {
            throw new InvalidArgumentException('A05 仅支持无章节、无正式状态的模拟小说');
        }
        $first = $novel->stages()->orderBy('id')->first();
        if ($first !== null) {
            $frozen = data_get($first->input, 'pipeline.enable_fine_outline');
            if (data_get($first->input, 'simulation') !== true || data_get($first->input, 'pipeline.version') !== 'a05-v1' || ! is_bool($frozen)) {
                throw new LogicException('既有阶段缺少 A05 模拟快照，拒绝接管');
            }
        } else {
            $frozen = $requested ?? app(GeneralSettings::class)->enable_fine_outline;
        }
        $plan = array_values(array_filter(Stage::cases(), static fn (Stage $stage): bool => $frozen || $stage !== Stage::FineOutline));

        return [$plan, $frozen];
    }

    /** 使用传入小说的连接重新加锁读取，忽略调用方可能已经过期的模型属性。 */
    private function lockedNovel(Novel $reference): Novel
    {
        if (! $reference->exists || $reference->getKey() === null) {
            throw new InvalidArgumentException('必须先保存小说再启动流水线');
        }

        return Novel::on($reference->getConnectionName())->whereKey($reference->getKey())->lockForUpdate()->firstOrFail();
    }

    /** 拒绝外层事务，保证执行器运行时领用记录已经提交且并发方可见。 */
    private function assertOutsideTransaction(Novel $novel): void
    {
        if ($novel->getConnection()->transactionLevel() !== 0) {
            throw new InvalidArgumentException('请在数据库事务之外调用流水线');
        }
    }
}
