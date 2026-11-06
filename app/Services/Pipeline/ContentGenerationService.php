<?php

namespace App\Services\Pipeline;

use App\Enums\ChapterStatus;
use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\User;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\TextRequestOptions;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** 正文唯一业务入口：首次生成、有限恢复及全文核对共用同一执行器。 */
final class ContentGenerationService
{
    /** 创建前冻结参数；仅本次明确确认才允许增加小说总额度。 */
    public function start(Novel $reference, User $author, int $maxOutputTokens = 8192, ?int $requestLimit = null, string $reason = '首章有限生成与检查', ?int $executionLimit = null, ?string $modelOverride = null): ChapterGenerationRun
    {
        $this->outsideTransaction();
        ContentSchema::text($reason);
        if ($maxOutputTokens < 1 || $maxOutputTokens > 8192) {
            throw new InvalidArgumentException('正文Token上限无效');
        }
        $run = DB::transaction(function () use ($reference, $author, $maxOutputTokens, $requestLimit, $reason, $executionLimit, $modelOverride): ChapterGenerationRun {
            $novel = Novel::lockForUpdate()->findOrFail($reference->id);
            $plan = $novel->outlinePlans()->where('status', 'completed')->latest('id')->firstOrFail();
            $fine = $plan->config['enable_fine_outline'];
            if (! is_bool($fine) || $novel->generation_mode !== GenerationMode::SettingReal || $novel->status !== NovelStatus::Paused
                || $novel->current_stage !== ($fine ? Stage::FineOutline : Stage::Content) || $novel->chapters()->exists()) {
                throw new InvalidArgumentException('不在首次正文生成边界');
            }
            $this->assertIdle($novel, false);
            $settings = app(GeneralSettings::class);
            $config = $this->configuration($modelOverride ?? $settings->default_model, $settings->temperature, $fine, $maxOutputTokens, $settings->max_retries_per_chapter, $author->id, $reason);
            $base = $fine ? 3 : 2;
            $limit = $this->limit($requestLimit, $base, $config['max_retries']);
            $snapshot = ['novel' => $novel->only(['id', 'title', 'genre', 'premise_raw', 'target_chapters', 'chapter_length_policy']),
                'confirmation_id' => $plan->confirmation->id, 'confirmation_hash' => $plan->confirmation->content_hash,
                'confirmed_setting' => $plan->confirmation->setting, 'decisions' => $plan->confirmation->decisions,
                'source_chapter' => $plan->chapters()->where('chapter_number', 1)->firstOrFail()->attributesToArray(), 'outline_plan_id' => $plan->id, 'roadmap' => $plan->roadmap];
            $chapter = $novel->chapters()->create(['number' => 1, 'title' => $snapshot['source_chapter']['title'], 'objective' => $snapshot['source_chapter']['objective'], 'status' => ChapterStatus::Pending]);
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

    /** 旧产物只能通过此入口转为新协议；新授权独立记录，旧授权绝不自动续用。 */
    public function recover(int $parentId, User $author, string $reason, ?int $requestLimit = null, ?int $executionLimit = null, bool $confirmUnknown = false, bool $rewrite = false, ?string $model = null): ChapterGenerationRun
    {
        $this->outsideTransaction();
        ContentSchema::text($reason);
        $reference = ChapterGenerationRun::findOrFail($parentId);
        [$run, $created] = DB::transaction(function () use ($reference, $author, $reason, $requestLimit, $executionLimit, $confirmUnknown, $rewrite, $model): array {
            $novel = Novel::lockForUpdate()->findOrFail($reference->chapter->novel_id);
            $parent = ChapterGenerationRun::lockForUpdate()->findOrFail($reference->id);
            if ($existing = ChapterGenerationRun::where('parent_run_id', $parent->id)->first()) {
                return [$existing, false];
            }
            if (! in_array($parent->status, ['paused', 'error', 'completed'], true) || $parent->stop_reason === 'b04_accepted' || $novel->status !== NovelStatus::Paused) {
                throw new InvalidArgumentException('当前运行不能恢复或验收已经完成');
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
            if ($step === 'revise' && count(ContentSchema::anchors($source->body)) < 2) {
                throw new InvalidArgumentException('源正文不足两段，无法保留范围外原文，请明确选择整章重写');
            }
            $settings = app(GeneralSettings::class);
            $config = $this->configuration($model ?? $parent->config['model'], $parent->config['temperature'], $fine, 8192, $settings->max_retries_per_chapter, $author->id, $reason);
            if ($step === 'revise' && $config['max_retries'] === 0) {
                throw new InvalidArgumentException('设置禁止修订，不能新建修订运行');
            }
            $base = $step === 'fine' ? 3 : 2;
            $limit = $step === 'revise' ? ($requestLimit ?? 2) : $this->limit($requestLimit, $base, $config['max_retries']);
            if ($step === 'revise' && $limit !== 2) {
                throw new InvalidArgumentException('恢复范围修订只允许修订和检查各一次');
            }
            $snapshot = $parent->snapshot;
            $snapshot['recovery'] = ['parent_run_id' => $parent->id, 'parent_input_hash' => $parent->input_hash, 'parent_status' => $parent->status,
                'parent_stop_reason' => $parent->stop_reason, 'source_version_id' => $source?->id, 'source_body_hash' => $source?->body_hash,
                'source_lineage' => $source === null ? [] : $this->recoveryLineage($parent, $source), 'plan_hash' => PlanningJson::hash($plan), 'fine_stage_id' => $parent->fine_stage_id];
            $config['request_cap'] = $limit;
            $config['initial_step'] = $step;
            // 冻结前轮响应契约错误，后继请求才能针对真实失败修复，旧响应不改写。
            $failedStage = $novel->stages()->where('input->run_id', $parent->id)->where('status', 'failed')->latest('id')->first();
            $config['recovery_validation_error'] = data_get($failedStage?->output, 'data.validation_error');
            $config['recovery_findings'] = app(ContentExecutionService::class)->findings($parent, $source);
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
                || ! in_array($run->stop_reason, ['execution_limit', 'dispatch_failed', 'reading_failed', 'user_paused'], true) || $novel->status !== NovelStatus::Paused
                || ($requestLimit !== null && $requestLimit !== $run->request_limit)) {
                throw new InvalidArgumentException('不能直接继续；旧协议或已发送的失败请求须使用显式恢复');
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
        if ($novel->stages()->where('status', 'running')->exists() || ChapterGenerationRun::whereHas('chapter', fn ($q) => $q->where('novel_id', $novel->id))->whereIn('status', ['pending', 'running'])->exists()
            || CostLog::where('novel_id', $novel->id)->where('status', 'pending')->exists()
            || (! $confirmUnknown && CostLog::where('novel_id', $novel->id)->where('status', 'unknown')->exists())) {
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
