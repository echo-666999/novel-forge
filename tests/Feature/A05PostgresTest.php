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
use App\Services\Pipeline\PipelineResult;
use App\Services\Pipeline\StageExecutorInterface;
use App\Services\Pipeline\StageResult;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** 使用 A05 专用 PostgreSQL 库验证持久化、独立连接可见性与额度原子领用。 */
class A05PostgresTest extends TestCase
{
    use DatabaseMigrations;

    /** 固定本任务隔离数据库，禁止复用既有任务或业务库。 */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.url', null);
        $app['config']->set('database.connections.pgsql.database', 'novel_forge_app_a05_test');

        return $app;
    }

    /** 清库前核对用途标记；仅执行应用迁移。
     * @return array<string,mixed>
     */
    protected function migrateFreshUsing(): array
    {
        $identity = DB::selectOne("select current_database() as name, shobj_description(oid,'pg_database') as purpose from pg_database where datname=current_database()");
        if ($identity->name !== 'novel_forge_app_a05_test' || $identity->purpose !== 'novel-forge A05 isolated tests') {
            throw new RuntimeException('A05 隔离库身份不匹配，拒绝迁移');
        }

        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 确定性执行无网络依赖。 */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** 关闭对等连接，避免迁移清理时遗留访问者。 */
    protected function tearDown(): void
    {
        DB::purge('a05_peer');
        Http::assertNothingSent();
        parent::tearDown();
    }

    /** 真实 PostgreSQL 中完成六阶段，JSONB 快照可读且没有正文或费用。 */
    public function test_full_pipeline_persists_ordered_json_snapshots_without_business_artifacts(): void
    {
        $novel = Novel::factory()->create(['max_execution_count' => 6]);
        $result = app(NovelPipelineService::class)->run($novel, true);
        $this->assertSame(NovelStatus::Completed, $result->status);
        $stages = $novel->stages()->orderBy('id')->get();
        $this->assertSame(Stage::cases(), $stages->pluck('stage')->all());
        foreach ($stages as $index => $stage) {
            $this->assertSame(StageStatus::Completed, $stage->status);
            $this->assertTrue($stage->input['simulation']);
            $this->assertTrue($stage->output['simulation']);
            $this->assertSame($index > 0 ? $stages[$index - 1]->output : null, $stage->input['previous_output']);
        }
        $this->assertSame(0, Chapter::query()->count());
        $this->assertSame(0, CostLog::query()->count());
        $this->assertSame('100.00', $novel->fresh()->progress);
    }

    /** 执行器事务外运行，对等连接必须看见已提交 running 记录并拒绝重复领用。 */
    public function test_peer_connection_sees_committed_claim_and_cannot_consume_last_quota_twice(): void
    {
        config(['database.connections.a05_peer' => config('database.connections.pgsql')]);
        $novel = Novel::factory()->create(['max_execution_count' => 1]);
        $peerResult = null;
        $executor = Mockery::mock(StageExecutorInterface::class);
        $executor->shouldReceive('execute')->once()->andReturnUsing(function (Novel $snapshot, NovelStage $stage) use (&$peerResult): StageResult {
            $this->assertSame(0, $snapshot->getConnection()->transactionLevel());
            $peer = Novel::on('a05_peer')->findOrFail($snapshot->id);
            $this->assertSame(NovelStatus::Running, $peer->status);
            $this->assertSame(1, $peer->execution_count);
            $this->assertSame(StageStatus::Running, $peer->stages()->sole()->status);
            $peerResult = app(NovelPipelineService::class)->run($peer);

            return (new FakeStageExecutor)->execute($snapshot, $stage);
        });
        $this->app->instance(StageExecutorInterface::class, $executor);
        $result = app(NovelPipelineService::class)->run($novel, true);
        $this->assertInstanceOf(PipelineResult::class, $peerResult);
        $this->assertSame('busy', $peerResult->reason);
        $this->assertSame(NovelStatus::Paused, $result->status);
        $this->assertSame(1, $novel->stages()->count());
        $this->assertSame(1, $novel->fresh()->execution_count);
    }

    /** 暂停后提升额度并通过新连接恢复，不重复执行已完成阶段。 */
    public function test_resume_from_another_connection_preserves_completed_records(): void
    {
        config(['database.connections.a05_peer' => config('database.connections.pgsql')]);
        $novel = Novel::factory()->create(['max_execution_count' => 5]);
        $pipeline = app(NovelPipelineService::class);
        $this->assertSame('execution_limit', $pipeline->run($novel, true)->reason);
        $before = $novel->stages()->orderBy('id')->get()->map(fn (NovelStage $stage) => $stage->getRawOriginal())->all();
        $novel->update(['max_execution_count' => 6]);
        $result = $pipeline->resume(Novel::on('a05_peer')->findOrFail($novel->id));
        $this->assertSame(NovelStatus::Completed, $result->status);
        $this->assertSame(6, $result->executionCount);
        $this->assertSame($before, $novel->stages()->orderBy('id')->limit(5)->get()->map(fn (NovelStage $stage) => $stage->getRawOriginal())->all());
    }
}
