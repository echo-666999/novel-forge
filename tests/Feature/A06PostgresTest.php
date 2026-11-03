<?php

namespace Tests\Feature;

use App\Enums\NovelStatus;
use App\Models\Chapter;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Services\Pipeline\NovelPipelineService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/** 仅在有用途标记的 A06 专用 PostgreSQL 库验证迁移与实际持久化。 */
class A06PostgresTest extends TestCase
{
    use DatabaseMigrations;

    /** 固定独立测试库，拒绝通过 PHPUnit 默认值或连接 URL 切换到开发库。 */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.url', null);
        $app['config']->set('database.connections.pgsql.database', 'novel_forge_app_a06_test');

        return $app;
    }

    /** 任何清库或迁移之前先检查库名与用途标记。
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        $identity = DB::selectOne("select current_database() as name, shobj_description(oid, 'pg_database') as purpose from pg_database where datname = current_database()");
        if ($identity->name !== 'novel_forge_app_a06_test' || $identity->purpose !== 'novel-forge A06 isolated tests') {
            throw new RuntimeException('A06 隔离库身份不匹配，拒绝迁移');
        }

        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 专项测试不允许真实模型或外部请求。 */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** 测试结束前核实模拟边界，再由框架回滚隔离库。 */
    protected function tearDown(): void
    {
        Http::assertNothingSent();
        parent::tearDown();
    }

    /** PostgreSQL 重读后的六阶段指标完整，既有阶段内容和未知指标保持不变。 */
    public function test_observations_roundtrip_without_changing_legacy_history(): void
    {
        $legacy = NovelStage::factory()->create(['input' => ['历史输入' => '原样保留'], 'output' => ['历史输出' => [1, 2]]])->refresh();
        $before = $legacy->getRawOriginal();
        $novel = Novel::factory()->create(['max_execution_count' => 6]);
        $this->assertSame(NovelStatus::Completed, app(NovelPipelineService::class)->run($novel, true)->status);
        foreach ($novel->stages()->orderBy('id')->get() as $stage) {
            $this->assertIsInt($stage->duration_ms);
            $this->assertGreaterThanOrEqual(0, $stage->duration_ms);
            $this->assertEquals(['billing_status' => 'simulated', 'currency' => null, 'estimated_cost' => '0.00000000', 'actual_cost' => '0.00000000'], $stage->cost);
            $this->assertSame($novel->premise_raw, $stage->input['novel']['premise_raw']);
            $this->assertTrue($stage->output['simulation']);
        }
        $this->assertSame($before, $legacy->fresh()->getRawOriginal());
        $this->assertNull($legacy->cost);
        $this->assertNull($legacy->duration_ms);
        $this->assertSame(0, CostLog::query()->count());
        $this->assertSame(0, Chapter::query()->count());
    }

    /** 核对表与每一栏的中文备注，新增类型和空值语义必须与实际数据库一致。 */
    public function test_columns_and_table_have_chinese_comments_and_expected_types(): void
    {
        $name = DB::connection()->getTablePrefix().'novel_stages';
        $columns = DB::select('select a.attname, format_type(a.atttypid, a.atttypmod) as type, col_description(c.oid, a.attnum) as comment, obj_description(c.oid) as table_comment, a.attnotnull from pg_class c join pg_namespace n on n.oid = c.relnamespace join pg_attribute a on a.attrelid = c.oid where n.nspname = current_schema() and c.relname = ? and a.attnum > 0 and not a.attisdropped', [$name]);
        $this->assertNotEmpty($columns);
        foreach ($columns as $column) {
            $this->assertMatchesRegularExpression('/\p{Han}/u', $column->comment ?? '', $column->attname);
            $this->assertMatchesRegularExpression('/\p{Han}/u', $column->table_comment ?? '');
        }
        $byName = collect($columns)->keyBy('attname');
        $this->assertSame('jsonb', $byName['cost']->type);
        $this->assertSame('bigint', $byName['duration_ms']->type);
        $this->assertFalse($byName['cost']->attnotnull);
        $this->assertFalse($byName['duration_ms']->attnotnull);
    }

    /** 数据库直接拒绝负耗时，零毫秒和未采集空值均合法。 */
    public function test_negative_duration_is_rejected_by_database(): void
    {
        NovelStage::factory()->create(['duration_ms' => 0]);
        NovelStage::factory()->create(['duration_ms' => null]);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23514');
        NovelStage::factory()->create(['duration_ms' => -1]);
    }

    /** 在隔离库模拟升级与回滚，只改变新增结构，不回填或修改旧列内容。 */
    public function test_incremental_migration_preserves_old_columns_without_backfill(): void
    {
        $migration = require database_path('migrations/2026_11_03_164201_add_observability_to_novel_stages_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('novel_stages', 'cost'));
        $stage = NovelStage::factory()->create(['input' => ['创意' => '寻找归途'], 'output' => ['历史' => true]]);
        $before = (array) DB::table('novel_stages')->find($stage->id);
        $migration->up();
        $after = (array) DB::table('novel_stages')->find($stage->id);
        $this->assertNull($after['cost']);
        $this->assertNull($after['duration_ms']);
        $this->assertSame($before, array_diff_key($after, ['cost' => true, 'duration_ms' => true]));
        $migration->down();
        $this->assertSame($before, (array) DB::table('novel_stages')->find($stage->id));
        // 恢复当前结构，让测试框架的正常迁移回滚仍可按原顺序执行。
        $migration->up();
    }
}
