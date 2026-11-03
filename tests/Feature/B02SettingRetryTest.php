<?php

namespace Tests\Feature;

use App\Enums\GenerationMode;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Jobs\RunSettingJob;
use App\Models\CostLog;
use App\Models\Novel;
use App\Services\Pipeline\CreateNovelService;
use App\Services\Pipeline\NovelPipelineService;
use App\Services\Pipeline\RetrySettingService;
use App\Services\Pipeline\SettingExecutor;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** 显式重试与队列中断的状态边界，不调用真实模型或持久业务库。 */
class B02SettingRetryTest extends TestCase
{
    use DatabaseMigrations;

    /** 仅使用内存库应用迁移。
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 固定离线凭据和假队列，防止测试意外发送请求。 */
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.providers.openai.key' => 'sk-b02-retry-test', 'ai.providers.openai.url' => 'https://api.openai.com/v1']);
        Queue::fake();
        Http::preventStrayRequests();
    }

    /** 只在已核对的内存库解除夹具回滚保护。 */
    protected function tearDown(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        DB::table('novels')->update(['generation_mode' => 'simulation']);
        parent::tearDown();
    }

    /** 构造明确拒绝或结果未知的失败尝试。
     * @return array{Novel, RunSettingJob}
     */
    private function failedNovel(int $httpStatus = 401): array
    {
        $novel = app(CreateNovelService::class)->createAndStart(B02SettingTest::input())->novel;
        $job = Queue::pushed(RunSettingJob::class)->sole();
        Http::fakeSequence('https://api.openai.com/v1/responses')->push(['error' => ['message' => '测试失败']], $httpStatus)->push(B02SettingTest::response(json_encode(B02SettingTest::setting())), 200);
        $job->handle(app(SettingExecutor::class));

        return [$novel->refresh(), $job];
    }

    /** 确认失败后的新尝试沿用冻结参数，旧失败、费用和次数均保留。 */
    public function test_explicit_retry_creates_new_attempt_and_preserves_old_evidence(): void
    {
        [$novel, $oldJob] = $this->failedNovel();
        $oldStage = $novel->stages()->sole()->getRawOriginal();
        $oldLog = CostLog::query()->sole()->getRawOriginal();
        Queue::fake();
        app(GeneralSettings::class)->default_model = 'changed-after-failure';
        $this->artisan('app:retry-setting', ['novel' => $novel->id])->assertExitCode(0)->expectsOutputToContain('已入队');
        $newJob = Queue::pushed(RunSettingJob::class)->sole();
        $this->assertNotSame($oldJob->invocationId, $newJob->invocationId);
        // 迟到的旧消息和失败回调不得领用或破坏新一轮的待运行状态。
        $oldJob->handle(app(SettingExecutor::class));
        $oldJob->failed(new RuntimeException('迟到错误'));
        $this->assertSame(NovelStatus::Pending, $novel->fresh()->status);
        $newJob->handle(app(SettingExecutor::class));
        $this->assertSame(2, $novel->fresh()->execution_count);
        $this->assertSame(2, $novel->stages()->count());
        $this->assertSame($oldStage, $novel->stages()->oldest('id')->first()->getRawOriginal());
        $this->assertSame($oldLog, CostLog::query()->oldest('id')->first()->getRawOriginal());
        $this->assertSame('gpt-4.1-mini', CostLog::query()->latest('id')->first()->requested_model);
        $this->assertSame(NovelStatus::Paused, $novel->fresh()->status);
        $this->assertSame(Stage::Outline, $novel->fresh()->current_stage);
        $this->artisan('app:retry-setting', ['novel' => $novel->id])->assertExitCode(1)->expectsOutputToContain('成功');
        Queue::assertPushed(RunSettingJob::class, 1);
    }

    /** 未知响应需要明确确认，确认只允许一次新投递，并不冲销旧费用。 */
    public function test_unknown_outcome_needs_additional_confirmation(): void
    {
        [$novel] = $this->failedNovel(503);
        $before = CostLog::query()->sole()->getRawOriginal();
        Queue::fake();
        $this->artisan('app:retry-setting', ['novel' => $novel->id])->assertExitCode(1)->expectsOutputToContain('--confirm-unknown');
        Queue::assertNothingPushed();
        $this->artisan('app:retry-setting', ['novel' => $novel->id, '--confirm-unknown' => true])->assertExitCode(0);
        $this->assertSame($before, CostLog::query()->sole()->getRawOriginal());
        $this->artisan('app:retry-setting', ['novel' => $novel->id, '--confirm-unknown' => true])->assertExitCode(1);
        Queue::assertPushed(RunSettingJob::class, 1);
    }

    /** 最后一次额度失败应暂停，显式重试也不能越过硬上限。 */
    public function test_failed_last_allowance_cannot_be_retried(): void
    {
        app(GeneralSettings::class)->max_execution_count = 1;
        [$novel] = $this->failedNovel();
        $this->assertSame(NovelStatus::Paused, $novel->status);
        Queue::fake();
        $this->artisan('app:retry-setting', ['novel' => $novel->id])->assertExitCode(1)->expectsOutputToContain('上限');
        Queue::assertNothingPushed();
        Http::assertSentCount(1);
    }

    /** 回执不确定但任务已经运行时，投递错误不能覆盖运行结果。 */
    public function test_retry_dispatch_failure_does_not_overwrite_a_claimed_novel(): void
    {
        [$novel] = $this->failedNovel();
        Bus::shouldReceive('dispatch')->once()->andReturnUsing(function (RunSettingJob $job): void {
            $this->assertSame(0, DB::transactionLevel());
            Novel::findOrFail($job->novelId)->update(['status' => NovelStatus::Running]);
            throw new RuntimeException('测试回执丢失');
        });
        $result = app(RetrySettingService::class)->retry($novel->id);
        $this->assertFalse($result->startupConfirmed);
        $this->assertSame(NovelStatus::Running, $novel->fresh()->status);
    }

    /** 未领取投递失败保持创意和旧尝试，允许作者核查后再显式重试。 */
    public function test_retry_dispatch_failure_preserves_saved_novel_and_attempts(): void
    {
        [$novel] = $this->failedNovel();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('测试 Redis 故障'));
        $result = app(RetrySettingService::class)->retry($novel->id);
        $this->assertFalse($result->startupConfirmed);
        $this->assertSame(NovelStatus::Error, $novel->fresh()->status);
        $this->assertSame(1, $novel->stages()->count());
        $this->assertSame(B02SettingTest::input()['premise_raw'], $novel->fresh()->premise_raw);
    }

    /** 流水线通用恢复不能绕过真实设定的未知结果确认。 */
    public function test_generic_resume_and_simulation_novel_are_not_retry_entrypoints(): void
    {
        [$novel] = $this->failedNovel(503);
        try {
            app(NovelPipelineService::class)->resume($novel);
            $this->fail('必须使用专用重试入口');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('app:retry-setting', $exception->getMessage());
        }
        $simulation = Novel::factory()->create(['status' => NovelStatus::Error]);
        $this->artisan('app:retry-setting', ['novel' => $simulation->id])->assertExitCode(1);
        $this->artisan('app:retry-setting', ['novel' => '-1'])->assertExitCode(1);
    }

    /** 队列中断回调可看见已提交 running 和 pending 意图，并保留未知费用。 */
    public function test_interruption_marks_only_owned_attempt_unknown(): void
    {
        $novel = app(CreateNovelService::class)->createAndStart(B02SettingTest::input())->novel;
        $job = Queue::pushed(RunSettingJob::class)->sole();
        Http::fake(['https://api.openai.com/v1/responses' => function () use ($novel, $job) {
            $this->assertSame(StageStatus::Running, $novel->stages()->sole()->status);
            $job->failed(new RuntimeException('测试队列中断'));
            throw new RuntimeException('中断后不再返回结果');
        }]);
        $job->handle(app(SettingExecutor::class));
        $stage = $novel->stages()->sole();
        $this->assertSame(StageStatus::Failed, $stage->status);
        $this->assertSame('unknown', $stage->output['data']['outcome']);
        $this->assertSame(NovelStatus::Error, $novel->fresh()->status);
        $this->assertSame('unknown', CostLog::query()->sole()->status);
        $before = $stage->getRawOriginal();
        $job->failed(new RuntimeException('重复失败通知'));
        $this->assertSame($before, $stage->fresh()->getRawOriginal());
    }

    /** 配置来自服务端，伪造设置不能扩展请求上限或替换模型。 */
    public function test_client_configuration_is_ignored_and_mode_is_validated(): void
    {
        $novel = app(CreateNovelService::class)->createAndStart([...B02SettingTest::input(), 'generation_config' => ['model' => 'forged', 'max_output_tokens' => 999999]])->novel;
        $this->assertSame('gpt-4.1-mini', $novel->generation_config['model']);
        $this->assertSame(4096, $novel->generation_config['max_output_tokens']);
        $this->assertSame(GenerationMode::SettingReal, $novel->generation_mode);
        $this->expectException(ValidationException::class);
        app(CreateNovelService::class)->createAndStart([...B02SettingTest::input(), 'generation_mode' => 'arbitrary']);
    }

    /** 回滚不能删除已有真实书的模式，防止历史被模拟路径接管。 */
    public function test_migration_preserves_legacy_defaults_and_protects_real_mode_on_rollback(): void
    {
        $legacy = Novel::factory()->create()->refresh();
        $this->assertSame(GenerationMode::Simulation, $legacy->generation_mode);
        $this->assertNull($legacy->generation_config);
        app(CreateNovelService::class)->createAndStart(B02SettingTest::input());
        $migration = require database_path('migrations/2026_11_03_183725_add_generation_mode_to_novels_table.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('拒绝删除生成模式');
        $migration->down();
    }
}
