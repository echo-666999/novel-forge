<?php

namespace Tests\Feature;

use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Filament\Resources\Novels\Pages\ViewNovelRun;
use App\Jobs\RunNovelPipelineJob;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Models\User;
use App\Services\Pipeline\FakeStageExecutor;
use App\Services\Pipeline\NovelPipelineService;
use App\Services\Pipeline\RunControlService;
use App\Services\Pipeline\StageExecutorInterface;
use App\Services\Pipeline\StageResult;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/** 通过真实后台组件验证失败阅读、异步重跑及安全暂停，不发送真实AI请求。 */
class B08RunRecoveryTest extends TestCase
{
    use DatabaseMigrations;

    /** 只迁移应用表，隔离于真实数据和供应商。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 使用私有后台账号和假队列，禁止外部网络。 */
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Queue::fake();
        Http::preventStrayRequests();
    }

    /** 运行台本身不产生模型请求或费用记录。 */
    protected function tearDown(): void
    {
        Http::assertNothingSent();
        $this->assertSame(0, CostLog::count());
        parent::tearDown();
    }

    /** 人为大纲失败后在后台阅读错误、重跑、消费任务并确认完成，旧失败保持原样。 */
    public function test_backend_failure_retry_and_recovery_preserve_history(): void
    {
        $executor = Mockery::mock(StageExecutorInterface::class);
        $executor->shouldReceive('execute')->andReturnUsing(fn ($novel, $stage) => $stage->stage === Stage::Outline
            ? StageResult::failed('B08受控大纲失败', ['partial' => '保留局部产物']) : (new FakeStageExecutor)->execute($novel, $stage));
        $this->app->instance(StageExecutorInterface::class, $executor);
        $novel = Novel::factory()->create(['max_execution_count' => 8]);
        app(NovelPipelineService::class)->run($novel, true);
        $failed = $novel->stages()->where('status', 'failed')->sole();
        $before = $failed->getRawOriginal();
        Livewire::test(ViewNovelRun::class, ['record' => $novel->id])->assertSuccessful()->assertSee('B08受控大纲失败')
            ->assertSee('重跑 / 继续模拟')->callAction('resumeRun', data: ['limit' => 8, 'reason' => 'B08重跑验收'])->assertNotified('运行操作已受理');
        Queue::assertPushed(RunNovelPipelineJob::class, 1);
        $this->assertSame(NovelStatus::Pending, $novel->fresh()->status);
        (new RunNovelPipelineJob($novel->id, true))->handle();
        $this->assertSame(NovelStatus::Completed, $novel->fresh()->status);
        $this->assertSame($before, $failed->fresh()->getRawOriginal());
        Livewire::test(ViewNovelRun::class, ['record' => $novel->id])->assertSee('模拟完成')->assertActionHidden('resumeRun');
    }

    /** 待领取任务先暂停，旧队列消息不能越过门禁；后台继续后可正常消费。 */
    public function test_pending_pause_and_resume_block_old_job(): void
    {
        $novel = Novel::factory()->create(['max_execution_count' => 6]);
        Livewire::test(ViewNovelRun::class, ['record' => $novel->id])->callAction('pauseRun', data: ['reason' => '暂停验收'])->assertNotified('运行操作已受理');
        (new RunNovelPipelineJob($novel->id, true))->handle();
        $this->assertSame(0, $novel->stages()->count());
        $this->assertSame(0, $novel->fresh()->execution_count);
        Livewire::test(ViewNovelRun::class, ['record' => $novel->id])->callAction('resumeRun', data: ['limit' => 6, 'reason' => '继续验收']);
        (new RunNovelPipelineJob($novel->id, true))->handle();
        $this->assertSame(NovelStatus::Completed, $novel->fresh()->status);
        $this->assertCount(2, $novel->fresh()->run_control_history);
    }

    /** 在途请求返回前点击暂停：成功结果保留，下一阶段不会被领取。 */
    public function test_inflight_pause_keeps_result_and_stops_next_stage(): void
    {
        $executor = Mockery::mock(StageExecutorInterface::class);
        $executor->shouldReceive('execute')->once()->andReturnUsing(function ($novel, $stage): StageResult {
            app(RunControlService::class)->pause($novel->id, auth()->user(), '请求期间暂停');
            $this->assertSame('busy', app(NovelPipelineService::class)->run($novel)->reason);
            $this->assertSame(NovelStatus::Running, $novel->fresh()->status);

            return (new FakeStageExecutor)->execute($novel, $stage);
        });
        $this->app->instance(StageExecutorInterface::class, $executor);
        $novel = Novel::factory()->create(['max_execution_count' => 6]);
        $result = app(NovelPipelineService::class)->run($novel, true);
        $this->assertSame('user_paused', $result->reason);
        $this->assertSame('completed', $novel->stages()->sole()->status->value);
        $this->assertSame(1, $novel->fresh()->execution_count);
        $this->assertSame(NovelStatus::Paused, $novel->fresh()->status);
    }

    /** 额度不足和过期重复恢复都明确拒绝，不增加队列消息或覆盖历史。 */
    public function test_limits_and_duplicate_resume_are_rejected(): void
    {
        $novel = Novel::factory()->create(['status' => NovelStatus::Paused, 'execution_count' => 5, 'max_execution_count' => 5]);
        Livewire::test(ViewNovelRun::class, ['record' => $novel->id])->callAction('resumeRun', data: ['limit' => 5, 'reason' => '额度不足'])->assertNotified('运行操作未执行');
        Queue::assertNothingPushed();
        app(RunControlService::class)->resumeSimulation($novel->id, auth()->user(), 6, '授权增加一次');
        $this->expectExceptionMessage('仅空闲且暂停或失败的模拟流水线可以恢复。');
        app(RunControlService::class)->resumeSimulation($novel->id, auth()->user(), 6, '重复操作');
    }

    /** 可复现浏览器验收失败，失败注入仅用于新建模拟小说。 */
    public function test_failure_fixture_command_never_uses_real_provider(): void
    {
        $this->artisan('app:verify-novel-pipeline', ['--max-execution-count' => 8, '--fail-stage' => 'outline'])->assertExitCode(1);
        $novel = Novel::sole();
        $this->assertSame(NovelStatus::Error, $novel->status);
        $this->assertSame('B08受控阶段失败：大纲', $novel->stages()->latest('id')->first()->error_message);
    }

    /** 其他小说的错误和产物不能泄漏到当前恢复台。 */
    public function test_history_is_scoped_to_current_novel(): void
    {
        $novel = Novel::factory()->create();
        NovelStage::factory()->create(['error_message' => '外部小说独有错误']);
        Livewire::test(ViewNovelRun::class, ['record' => $novel->id])->assertSuccessful()->assertDontSee('外部小说独有错误')->assertSee('未执行');
    }
}
