<?php

namespace Tests\Feature;

use App\Enums\Stage;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelOutlineChapter;
use App\Models\NovelSettingConfirmation;
use App\Models\NovelStage;
use App\Models\User;
use App\Services\Pipeline\OutlineExecutor;
use App\Services\Pipeline\OutlineGenerationService;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** 仅在 B03 专用 PostgreSQL 库执行迁移、备注、JSONB 和并发可见性验收。 */
class B03PostgresTest extends TestCase
{
    use DatabaseMigrations;

    /** 清理夹具前必须已经验证隔离库身份。 */
    private bool $identityVerified = false;

    /** 保留只读探测连接；业务测试连接严格固定为 B03 专库。 */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $probe = $app['config']->get('database.connections.pgsql');
        $probe['url'] = null;
        // PHPUnit 将 DB_DATABASE 设为 :memory:；目录探测显式使用已知开发库且只读系统目录。
        $probe['database'] = 'novel_forge_app';
        $app['config']->set('database.connections.b03_probe', $probe);
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.url', null);
        $app['config']->set('database.connections.pgsql.database', 'novel_forge_app_b03_test');

        return $app;
    }

    /** 未建库时明确 incomplete，不能跳过后宣称专项通过。
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        $present = DB::connection('b03_probe')->selectOne('select exists(select 1 from pg_database where datname=?) as present', ['novel_forge_app_b03_test']);
        if (! $present->present) {
            $this->markTestIncomplete('B03 专用 PostgreSQL 库尚未由管理员创建');
        }
        $identity = DB::selectOne("select current_database() as name, pg_get_userbyid(datdba) as owner, shobj_description(oid, 'pg_database') as purpose from pg_database where datname=current_database()");
        if ($identity->name !== 'novel_forge_app_b03_test' || $identity->owner !== 'novel_forge_app' || $identity->purpose !== 'novel-forge B03 isolated tests') {
            throw new RuntimeException('B03 隔离库身份或用途不符，拒绝迁移');
        }
        $this->identityVerified = true;

        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 所有供应商请求均由测试响应接管。 */
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['ai.providers.openai.key' => 'sk-b03-postgres-test', 'ai.providers.openai.url' => 'https://api.openai.com/v1']);
    }

    /** 只清理已核实身份的专库夹具，不操作其他任务或开发库。 */
    protected function tearDown(): void
    {
        if ($this->identityVerified) {
            foreach (['novel_outline_chapters', 'novel_outline_plans', 'novel_setting_confirmations'] as $table) {
                DB::table($table)->delete();
            }
            DB::table('novels')->update(['generation_mode' => 'simulation']);
        }
        DB::purge('b03_peer');
        DB::purge('b03_probe');
        parent::tearDown();
    }

    /** 核对所有新表和字段中文备注、JSONB 类型及确认摘要往返。 */
    public function test_schema_comments_jsonb_and_immutable_content_roundtrip(): void
    {
        foreach (['novel_setting_confirmations', 'novel_outline_plans', 'novel_outline_chapters'] as $table) {
            $name = DB::getTablePrefix().$table;
            $row = DB::selectOne('select obj_description(to_regclass(?)) as comment', [$name]);
            $this->assertMatchesRegularExpression('/\p{Han}/u', $row->comment);
            $columns = DB::select('select attname, col_description(attrelid,attnum) as comment from pg_attribute where attrelid=to_regclass(?) and attnum>0 and not attisdropped', [$name]);
            foreach ($columns as $column) {
                $this->assertMatchesRegularExpression('/\p{Han}/u', $column->comment);
            }
        }
        $confirmation = NovelSettingConfirmation::factory()->create();
        $this->assertTrue($confirmation->fresh()->hasValidHash());
        $this->assertSame(PlanningJson::hash($confirmation->setting), PlanningJson::hash($confirmation->fresh()->setting));
        $types = DB::selectOne('select pg_typeof(setting)::text as setting, pg_typeof(decisions)::text as decisions from '.DB::connection()->getQueryGrammar()->wrapTable('novel_setting_confirmations').' where id=?', [$confirmation->id]);
        $this->assertSame('jsonb', $types->setting);
        $this->assertSame('jsonb', $types->decisions);
    }

    /** 唯一章序、来源外键、正整数和游标边界由 PostgreSQL 真正拒绝。 */
    public function test_database_enforces_plan_and_chapter_constraints(): void
    {
        $chapter = NovelOutlineChapter::factory()->create();
        $this->rejects(fn () => NovelOutlineChapter::query()->create($chapter->only(['outline_plan_id', 'source_stage_id', 'chapter_number', 'title', 'summary', 'objective', 'conflict', 'hook', 'progression_points'])), '23505');
        $this->rejects(fn () => DB::table('novel_outline_chapters')->where('id', $chapter->id)->update(['chapter_number' => 0]), '23514');
        $this->rejects(fn () => DB::table('novel_outline_plans')->where('id', $chapter->outline_plan_id)->update(['next_chapter' => 12]), '23514');
        $this->rejects(fn () => DB::table('novel_outline_chapters')->where('id', $chapter->id)->update(['source_stage_id' => 999999]), '23503');
        $this->rejects(fn () => DB::table('novel_outline_plans')->where('id', $chapter->outline_plan_id)->delete(), '23001');
    }

    /** 请求期间另一个连接能看见提交的领用与意图，重复消息不能再次调用模型。 */
    public function test_peer_observes_committed_claim_and_cannot_claim_same_batch(): void
    {
        $confirmation = NovelSettingConfirmation::factory()->create();
        $plan = app(OutlineGenerationService::class)->start($confirmation->novel, $confirmation->id, User::factory()->create());
        config(['database.connections.b03_peer' => config('database.connections.pgsql')]);
        Http::fake(['https://api.openai.com/v1/responses' => function () use ($plan) {
            $this->assertSame(0, DB::transactionLevel());
            $peer = DB::connection('b03_peer');
            $this->assertSame('running', $peer->table('novel_outline_plans')->where('id', $plan->id)->value('status'));
            $this->assertSame('pending', $peer->table('cost_logs')->where('novel_id', $plan->novel_id)->value('status'));
            $previousConnection = DB::getDefaultConnection();
            try {
                DB::setDefaultConnection('b03_peer');
                app(OutlineGenerationService::class)->runBatch($plan->id, $plan->invocation_id, app(OutlineExecutor::class));
            } finally {
                DB::setDefaultConnection($previousConnection);
            }

            return Http::response(B02SettingTest::response(json_encode(B03OutlineTest::batch())));
        }]);
        app(OutlineGenerationService::class)->runBatch($plan->id, $plan->invocation_id, app(OutlineExecutor::class));
        $this->assertSame('completed', $plan->fresh()->status);
        $this->assertSame(10, $plan->chapters()->count());
        $this->assertSame(1, CostLog::query()->where('novel_id', $plan->novel_id)->count());
        $this->assertSame(1, NovelStage::query()->where('novel_id', $plan->novel_id)->where('stage', Stage::Outline->value)->count());
        Http::assertSentCount(1);
    }

    /** 仅重建空的新表时，既有小说和阶段字段保持完全不变。 */
    public function test_incremental_migration_preserves_existing_business_rows(): void
    {
        $stage = NovelStage::factory()->create();
        $beforeNovel = $stage->novel->fresh()->getRawOriginal();
        $beforeStage = $stage->fresh()->getRawOriginal();
        $migration = require database_path('migrations/2026_11_03_190822_create_b03_planning_tables.php');
        $migration->down();
        $migration->up();
        $this->assertSame($beforeNovel, Novel::findOrFail($stage->novel_id)->getRawOriginal());
        $this->assertSame($beforeStage, $stage->fresh()->getRawOriginal());
    }

    /** 每个失败写入独立回滚，避免 PostgreSQL 错误事务污染后续断言。 */
    private function rejects(callable $operation, string $code): void
    {
        try {
            DB::transaction($operation);
            $this->fail('数据库应拒绝非法写入');
        } catch (QueryException $exception) {
            $this->assertSame($code, $exception->errorInfo[0]);
        }
    }
}
