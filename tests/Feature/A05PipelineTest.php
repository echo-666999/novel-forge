<?php

namespace Tests\Feature;

use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Models\Chapter;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Services\Pipeline\FakeStageExecutor;
use App\Services\Pipeline\NovelPipelineService;
use App\Services\Pipeline\StageExecutorInterface;
use App\Services\Pipeline\StageResult;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** 覆盖固定模拟流水线与恢复边界，不使用真实模型或队列消费者。 */
class A05PipelineTest extends TestCase
{
    use DatabaseMigrations;

    /** 仅迁移应用表，排除第三方 PostgreSQL 扩展迁移。
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 所有场景均禁止意外 HTTP，并确保测试后没有模型费用记录。 */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** 模拟阶段不能偷偷调用模型网关。 */
    protected function tearDown(): void
    {
        Http::assertNothingSent();
        $this->assertSame(0, CostLog::query()->count());
        parent::tearDown();
    }

    /** 完整六阶段在第六次成功时完成，不生成章节或正式状态。 */
    public function test_six_stages_complete_exactly_at_limit_with_snapshot_handoff(): void
    {
        $novel = Novel::factory()->create(['max_execution_count' => 6]);
        $result = app(NovelPipelineService::class)->run($novel, true);
        $this->assertSame(NovelStatus::Completed, $result->status);
        $this->assertSame('completed', $result->reason);
        $this->assertSame(6, $result->executionCount);
        $this->assertSame('100.00', $result->progress);
        $stages = $novel->stages()->orderBy('id')->get();
        $this->assertSame(Stage::cases(), $stages->pluck('stage')->all());
        foreach ($stages as $index => $stage) {
            $this->assertSame(StageStatus::Completed, $stage->status);
            $this->assertSame(1, $stage->execution_count);
            $this->assertTrue($stage->input['simulation']);
            $this->assertTrue($stage->output['simulation']);
            $this->assertSame($index > 0 ? $stages[$index - 1]->id : null, $stage->input['previous_stage_id']);
            $this->assertSame($index > 0 ? $stages[$index - 1]->output : null, $stage->input['previous_output']);
            $this->assertNotNull($stage->started_at);
            $this->assertNotNull($stage->finished_at);
        }
        $this->assertSame(0, Chapter::query()->count());
        $this->assertSame(0, $novel->fresh()->chapters_done);
        $this->assertSame(0, $novel->fresh()->chapters_total);
        $this->assertSame(0, $novel->fresh()->state_version);
    }

    /** 细纲关闭时只执行五阶段，不为跳过步骤创建记录或消耗次数。 */
    public function test_disabling_fine_outline_completes_five_stages(): void
    {
        $novel = Novel::factory()->create();
        $result = app(NovelPipelineService::class)->run($novel, false);
        $this->assertSame(NovelStatus::Completed, $result->status);
        $this->assertSame(5, $result->executionCount);
        $this->assertSame(['setting', 'outline', 'content', 'polish', 'export'], $novel->stages()->orderBy('id')->get()->map(fn (NovelStage $stage) => $stage->stage->value)->all());
    }

    /** 默认上限五在导出前暂停，重复普通调用不改变暂停状态和计数。 */
    public function test_default_limit_pauses_before_export_and_requires_explicit_resume(): void
    {
        $novel = Novel::factory()->create();
        $pipeline = app(NovelPipelineService::class);
        $result = $pipeline->run($novel, true);
        $this->assertSame(NovelStatus::Paused, $result->status);
        $this->assertSame(Stage::Export, $result->currentStage);
        $this->assertSame('execution_limit', $result->reason);
        $this->assertSame(5, $novel->stages()->count());
        $this->assertSame('83.33', $result->progress);
        $this->assertSame('execution_limit', $pipeline->run($novel)->reason);
        $this->assertSame('execution_limit', $pipeline->resume($novel)->reason);
        $novel->update(['max_execution_count' => 6]);
        $this->assertSame('resume_required', $pipeline->run($novel)->reason);
        $completed = $pipeline->resume($novel);
        $this->assertSame(NovelStatus::Completed, $completed->status);
        $this->assertSame(6, $completed->executionCount);
        $this->assertSame(6, $novel->stages()->count());
        $this->assertSame(1, $novel->stages()->where('stage', 'setting')->count());
    }

    /** 一次额度只执行设定；恢复沿用已冻结细纲，不读取变化后的全局开关。 */
    public function test_limit_one_and_changed_settings_do_not_change_frozen_stage_order(): void
    {
        $novel = Novel::factory()->create(['max_execution_count' => 1]);
        $pipeline = app(NovelPipelineService::class);
        $result = $pipeline->run($novel, true);
        $this->assertSame(Stage::Outline, $result->currentStage);
        $this->assertSame(1, $result->executionCount);
        $settings = app(GeneralSettings::class);
        $settings->enable_fine_outline = false;
        $settings->save();
        $novel->update(['max_execution_count' => 6]);
        $result = $pipeline->resume($novel);
        $this->assertSame(NovelStatus::Completed, $result->status);
        $this->assertSame(1, $novel->stages()->where('stage', 'fine_outline')->count());
        $this->assertSame(6, $result->executionCount);
    }

    /** 已经用完额度时不领用新尝试，也不让过期模型实例的次数覆盖数据库。 */
    public function test_exhausted_limit_and_stale_model_cannot_allocate_extra_attempts(): void
    {
        $novel = Novel::factory()->create(['max_execution_count' => 1]);
        Novel::query()->whereKey($novel->id)->update(['execution_count' => 1]);
        $result = app(NovelPipelineService::class)->run($novel);
        $this->assertSame(NovelStatus::Paused, $result->status);
        $this->assertSame(1, $result->executionCount);
        $this->assertSame(0, $novel->stages()->count());
    }

    /** 失败局部停留，显式恢复仅新增本阶段第二次尝试，不清零累计次数。 */
    public function test_failed_stage_is_retained_and_only_retried_by_explicit_resume(): void
    {
        $executor = Mockery::mock(StageExecutorInterface::class);
        $executor->shouldReceive('execute')->andReturnUsing(function (Novel $novel, NovelStage $stage): StageResult {
            $this->assertSame(0, $novel->getConnection()->transactionLevel());
            if ($stage->stage === Stage::Outline && $stage->execution_count === 1) {
                return StageResult::failed('模拟大纲失败', ['partial' => '已有假数据']);
            }

            return (new FakeStageExecutor)->execute($novel, $stage);
        });
        $this->app->instance(StageExecutorInterface::class, $executor);
        $novel = Novel::factory()->create(['max_execution_count' => 7]);
        $pipeline = app(NovelPipelineService::class);
        $result = $pipeline->run($novel, true);
        $this->assertSame(NovelStatus::Error, $result->status);
        $this->assertSame(Stage::Outline, $result->currentStage);
        $this->assertSame(2, $result->executionCount);
        $failed = $novel->stages()->where('status', 'failed')->sole();
        $before = $failed->getRawOriginal();
        $this->assertSame('已有假数据', $failed->output['data']['partial']);
        $this->assertSame('resume_required', $pipeline->run($novel)->reason);
        $this->assertSame(2, $novel->stages()->count());
        $completed = $pipeline->resume($novel);
        $this->assertSame(NovelStatus::Completed, $completed->status);
        $this->assertSame(7, $completed->executionCount);
        $this->assertSame($before, $failed->fresh()->getRawOriginal());
        $this->assertSame([1, 2], $novel->stages()->where('stage', 'outline')->orderBy('id')->pluck('execution_count')->all());
    }

    /** 异常转换为安全失败说明，刚好用尽额度则暂停而非继续或重试。 */
    public function test_exception_at_limit_pauses_and_does_not_store_exception_message(): void
    {
        $executor = Mockery::mock(StageExecutorInterface::class);
        $executor->shouldReceive('execute')->once()->andThrow(new RuntimeException('不应记录的敏感异常消息'));
        $this->app->instance(StageExecutorInterface::class, $executor);
        $novel = Novel::factory()->create(['max_execution_count' => 1]);
        $result = app(NovelPipelineService::class)->run($novel);
        $this->assertSame(NovelStatus::Paused, $result->status);
        $stage = $novel->stages()->sole();
        $this->assertSame(StageStatus::Failed, $stage->status);
        $this->assertStringContainsString('RuntimeException', $stage->error_message);
        $this->assertStringNotContainsString('敏感异常', $stage->error_message);
        $this->assertNotNull($stage->finished_at);
    }

    /** 已完成任务重复 run/resume 都不创建记录或修改完成结果。 */
    public function test_completed_pipeline_is_idempotent(): void
    {
        $novel = Novel::factory()->create(['max_execution_count' => 6]);
        $pipeline = app(NovelPipelineService::class);
        $pipeline->run($novel, true);
        $before = $novel->fresh()->getRawOriginal();
        $this->assertSame('already_completed', $pipeline->run($novel, false)->reason);
        $this->assertSame('already_completed', $pipeline->resume($novel)->reason);
        $this->assertSame($before, $novel->fresh()->getRawOriginal());
        $this->assertSame(6, $novel->stages()->count());
    }

    /** 失去尝试归属的旧结果不能覆盖人工结束的记录或启动下一阶段。 */
    public function test_late_result_cannot_overwrite_finished_attempt(): void
    {
        $executor = Mockery::mock(StageExecutorInterface::class);
        $executor->shouldReceive('execute')->once()->andReturnUsing(function (Novel $novel, NovelStage $stage): StageResult {
            $stage->update(['status' => StageStatus::Failed, 'output' => ['marker' => '保留的人工结果'], 'error_message' => '人工结束', 'finished_at' => now()]);
            $novel->update(['status' => NovelStatus::Paused]);

            return StageResult::completed(['marker' => '不得覆盖的旧结果']);
        });
        $this->app->instance(StageExecutorInterface::class, $executor);
        $novel = Novel::factory()->create(['max_execution_count' => 6]);
        $result = app(NovelPipelineService::class)->run($novel);
        $this->assertSame('stale_result', $result->reason);
        $stage = $novel->stages()->sole();
        $this->assertSame(['marker' => '保留的人工结果'], $stage->output);
        $this->assertSame(StageStatus::Failed, $stage->status);
        $this->assertSame(1, $novel->fresh()->execution_count);
    }

    /** 拒绝接管既有非模拟阶段或已有章节的小说。 */
    public function test_non_simulation_records_and_existing_chapters_are_not_taken_over(): void
    {
        $legacy = NovelStage::factory()->create();
        try {
            app(NovelPipelineService::class)->run($legacy->novel);
            $this->fail('旧阶段不得被接管');
        } catch (LogicException) {
            $this->assertSame(0, $legacy->novel->fresh()->execution_count);
        }
        $chapter = Chapter::factory()->create();
        try {
            app(NovelPipelineService::class)->run($chapter->novel);
            $this->fail('已有章节不得用于模拟');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, $chapter->novel->stages()->count());
        }
    }

    /** 外层事务或未经保存的模型不能启动执行器。 */
    public function test_outer_transaction_and_unsaved_model_are_rejected(): void
    {
        $novel = Novel::factory()->create();
        DB::beginTransaction();
        try {
            app(NovelPipelineService::class)->run($novel);
            $this->fail('外层事务必须被拒绝');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, $novel->stages()->count());
        } finally {
            DB::rollBack();
        }
        $this->expectException(InvalidArgumentException::class);
        app(NovelPipelineService::class)->run(Novel::factory()->make());
    }

    /** 不允许从任意阶段跳跃启动，也不把 pending 当作恢复操作。 */
    public function test_invalid_stage_pointer_and_illegal_resume_are_rejected(): void
    {
        $novel = Novel::factory()->create(['current_stage' => Stage::Export]);
        try {
            app(NovelPipelineService::class)->run($novel);
            $this->fail('不得跳过阶段');
        } catch (LogicException) {
            $this->assertSame(0, $novel->stages()->count());
        }
        $this->expectException(InvalidArgumentException::class);
        app(NovelPipelineService::class)->resume($novel);
    }

    /** 命令区分完成、额度暂停和参数失败，并只新建有标识的合成小说。 */
    public function test_command_exit_codes_and_synthetic_record_boundary(): void
    {
        $this->artisan('app:verify-novel-pipeline', ['--max-execution-count' => '6', '--json' => true])->assertExitCode(0)->expectsOutputToContain('completed');
        $this->artisan('app:verify-novel-pipeline', ['--json' => true])->assertExitCode(2)->expectsOutputToContain('execution_limit');
        $this->artisan('app:verify-novel-pipeline', ['--disable-fine-outline' => true])->assertExitCode(0);
        $this->artisan('app:verify-novel-pipeline', ['--max-execution-count' => '0'])->assertExitCode(1);
        $this->assertSame(3, Novel::query()->count());
        $this->assertSame(3, Novel::query()->where('title', 'like', '[A05验收]%')->count());
        $this->assertSame(0, Chapter::query()->count());
    }
}
