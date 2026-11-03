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
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** 使用隔离 SQLite 验证阶段观测、历史保护和只读命令，不调用真实模型。 */
class A06ObservabilityTest extends TestCase
{
    use DatabaseMigrations;

    /** 只执行应用迁移，排除 PostgreSQL 扩展迁移。
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 禁止意外外部请求，模拟观察功能不依赖供应商。 */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** 任何阶段观测不得凭空生成章节或请求费用明细。 */
    protected function tearDown(): void
    {
        Http::assertNothingSent();
        $this->assertSame(0, CostLog::query()->count());
        $this->assertSame(0, Chapter::query()->count());
        parent::tearDown();
    }

    /** 六阶段完整记录实际输入、传递链、模拟费用和非负毫秒耗时。 */
    public function test_six_stages_capture_inputs_simulated_cost_and_duration(): void
    {
        $novel = Novel::factory()->create(['title' => '中文验收', 'max_execution_count' => 6]);
        $result = app(NovelPipelineService::class)->run($novel, true);
        $this->assertSame(NovelStatus::Completed, $result->status);
        $stages = $novel->stages()->orderBy('id')->get();
        $this->assertCount(6, $stages);
        foreach ($stages as $index => $stage) {
            $this->assertSame('中文验收', $stage->input['novel']['title']);
            $this->assertSame($novel->premise_raw, $stage->input['novel']['premise_raw']);
            $this->assertSame($novel->chapter_length_policy, $stage->input['novel']['chapter_length_policy']);
            $this->assertSame(['execution_count' => $index + 1, 'max_execution_count' => 6, 'attempt_no' => 1], $stage->input['execution']);
            $this->assertSame($index === 0 ? null : $stages[$index - 1]->output, $stage->input['previous_output']);
            $this->assertSame('simulated', $stage->cost['billing_status']);
            $this->assertSame('0.00000000', $stage->cost['actual_cost']);
            $this->assertNull($stage->cost['currency']);
            $this->assertIsInt($stage->duration_ms);
            $this->assertGreaterThanOrEqual(0, $stage->duration_ms);
            $this->assertNotNull($stage->started_at);
            $this->assertNotNull($stage->finished_at);
        }
    }

    /** 大段中文及嵌套输出原样持久化，running 状态不提前宣称已采集指标。 */
    public function test_complete_output_and_unknown_cost_survive_database_roundtrip(): void
    {
        $output = ['文本' => str_repeat('完整中文片段', 1200), '人物' => [['姓名' => '归舟', '关系' => ['师徒', '同门']]]];
        $executor = Mockery::mock(StageExecutorInterface::class);
        $executor->shouldReceive('execute')->once()->andReturnUsing(function (Novel $novel, NovelStage $stage) use ($output): StageResult {
            $this->assertNull($stage->fresh()->duration_ms);
            $this->assertNull($stage->fresh()->cost);
            usleep(2000);

            return StageResult::completed($output);
        });
        $this->app->instance(StageExecutorInterface::class, $executor);
        $novel = Novel::factory()->create(['max_execution_count' => 1]);
        app(NovelPipelineService::class)->run($novel, true);
        $stage = $novel->stages()->sole();
        $this->assertSame($output, $stage->output['data']);
        $this->assertSame('unknown', $stage->cost['billing_status']);
        $this->assertNull($stage->cost['actual_cost']);
        $this->assertGreaterThanOrEqual(1, $stage->duration_ms);
    }

    /** 失败保留局部输出、估算费用和耗时，恢复只新增尝试而不改写失败历史。 */
    public function test_failed_attempt_metrics_survive_explicit_resume(): void
    {
        $cost = ['billing_status' => 'estimated', 'currency' => 'USD', 'estimated_cost' => '0.00012345', 'actual_cost' => null];
        $executor = Mockery::mock(StageExecutorInterface::class);
        $executor->shouldReceive('execute')->andReturnUsing(function (Novel $novel, NovelStage $stage) use ($cost): StageResult {
            if ($stage->stage === Stage::Outline && $stage->execution_count === 1) {
                return StageResult::failed('模拟大纲未完成', ['局部' => ['已有结果']], $cost);
            }

            return (new FakeStageExecutor)->execute($novel, $stage);
        });
        $this->app->instance(StageExecutorInterface::class, $executor);
        $novel = Novel::factory()->create(['max_execution_count' => 7]);
        $pipeline = app(NovelPipelineService::class);
        $this->assertSame('failed', $pipeline->run($novel, true)->reason);
        $failed = $novel->stages()->where('status', 'failed')->sole();
        $before = $failed->getRawOriginal();
        $this->assertSame($cost, $failed->cost);
        $this->assertSame(['已有结果'], $failed->output['data']['局部']);
        $this->assertIsInt($failed->duration_ms);
        $this->assertSame(NovelStatus::Completed, $pipeline->resume($novel)->status);
        $this->assertSame($before, $failed->fresh()->getRawOriginal());
        $this->assertSame('already_completed', $pipeline->run($novel)->reason);
        $this->assertSame($before, $failed->fresh()->getRawOriginal());
    }

    /** 异常也有耗时，但无法确认的费用和敏感异常内容不得伪造或泄露。 */
    public function test_exception_persists_unknown_cost_and_safe_error(): void
    {
        $executor = Mockery::mock(StageExecutorInterface::class);
        $executor->shouldReceive('execute')->once()->andThrow(new RuntimeException('secret-不得保存'));
        $this->app->instance(StageExecutorInterface::class, $executor);
        $novel = Novel::factory()->create(['max_execution_count' => 1]);
        app(NovelPipelineService::class)->run($novel);
        $stage = $novel->stages()->sole();
        $this->assertSame(StageStatus::Failed, $stage->status);
        $this->assertSame('unknown', $stage->cost['billing_status']);
        $this->assertIsInt($stage->duration_ms);
        $this->assertStringNotContainsString('secret', $stage->error_message);
    }

    /** 失去归属的返回结果不得覆盖已结束尝试的任何观测证据。 */
    public function test_late_result_cannot_overwrite_metrics(): void
    {
        $executor = Mockery::mock(StageExecutorInterface::class);
        $executor->shouldReceive('execute')->once()->andReturnUsing(function (Novel $novel, NovelStage $stage): StageResult {
            $stage->update(['status' => StageStatus::Failed, 'duration_ms' => 321, 'output' => ['先前结果' => true]]);
            $novel->update(['status' => NovelStatus::Error]);

            return (new FakeStageExecutor)->execute($novel, $stage);
        });
        $this->app->instance(StageExecutorInterface::class, $executor);
        $novel = Novel::factory()->create();
        $this->assertSame('stale_result', app(NovelPipelineService::class)->run($novel)->reason);
        $stage = $novel->stages()->sole();
        $this->assertSame(321, $stage->duration_ms);
        $this->assertNull($stage->cost);
        $this->assertSame(['先前结果' => true], $stage->output);
    }

    /** 额度暂停后显式恢复只写新阶段，既有快照、费用与耗时保持原样。 */
    public function test_paused_pipeline_preserves_observations_when_resumed(): void
    {
        $novel = Novel::factory()->create();
        $pipeline = app(NovelPipelineService::class);
        $this->assertSame('execution_limit', $pipeline->run($novel, true)->reason);
        $before = $novel->stages()->orderBy('id')->get()->map->getRawOriginal()->all();
        $novel->update(['max_execution_count' => 6]);
        $this->assertSame(NovelStatus::Completed, $pipeline->resume($novel)->status);
        $this->assertSame($before, $novel->stages()->orderBy('id')->limit(5)->get()->map->getRawOriginal()->all());
        $this->assertSame(6, $novel->stages()->orderByDesc('id')->first()->input['execution']['max_execution_count']);
    }

    /** 命令完整读取中文及旧指标空值，查询前后的原始数据库记录一致。 */
    public function test_query_command_is_read_only_and_does_not_truncate_snapshots(): void
    {
        $novel = Novel::factory()->create();
        $stage = NovelStage::factory()->for($novel)->create(['input' => ['原文' => str_repeat('中文', 2000)], 'output' => ['嵌套' => [1, 2]]])->refresh();
        $before = $stage->getRawOriginal();
        $this->assertSame(0, Artisan::call('app:show-novel-stages', ['novel' => $novel->id, '--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['count']);
        $this->assertSame($stage->toExecutionRecord(), $report['stages'][0]);
        $this->assertSame(0, Artisan::call('app:show-novel-stages', ['novel' => $novel->id, '--stage-id' => $stage->id]));
        $this->assertSame($before, $stage->fresh()->getRawOriginal());
        $this->assertSame(1, $novel->stages()->count());
        $this->assertSame(0, $novel->fresh()->execution_count);
    }

    /** 空小说正常返回空列表，非法标识、不存在记录和跨小说查询均明确失败。 */
    public function test_query_command_handles_empty_missing_and_foreign_records(): void
    {
        $novel = Novel::factory()->create();
        $foreign = NovelStage::factory()->create();
        $this->assertSame(0, Artisan::call('app:show-novel-stages', ['novel' => $novel->id]));
        $this->assertSame([], json_decode(Artisan::output(), true)['stages']);
        foreach ([['novel' => 0], ['novel' => 'abc'], ['novel' => 999999], ['novel' => $novel->id, '--stage-id' => null], ['novel' => $novel->id, '--stage-id' => ''], ['novel' => $novel->id, '--stage-id' => 'abc'], ['novel' => $novel->id, '--stage-id' => 999999], ['novel' => $novel->id, '--stage-id' => $foreign->id]] as $arguments) {
            $this->assertSame(1, Artisan::call('app:show-novel-stages', $arguments));
            $this->assertArrayHasKey('error', json_decode(Artisan::output(), true));
        }
        $this->assertSame(1, NovelStage::query()->count());
    }

    /** 原有空跑命令输出新指标，同时保留原有退出码和模拟边界。 */
    public function test_verification_command_includes_persisted_metrics(): void
    {
        $this->assertSame(0, Artisan::call('app:verify-novel-pipeline', ['--max-execution-count' => 6, '--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(6, $report['stages']);
        foreach ($report['stages'] as $stage) {
            $this->assertSame('simulated', $stage['cost']['billing_status']);
            $this->assertIsInt($stage['duration_ms']);
            $this->assertNotNull($stage['finished_at']);
        }
    }
}
