<?php

namespace Tests\Feature;

use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Services\Ai\AiTextGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** 在带用途标记的 A03 专用 PostgreSQL 库验收结构与持久化，不调用真实模型。 */
class A03PostgresTest extends TestCase
{
    use DatabaseMigrations;

    /** 固定本任务独立库，不读取 PHPUnit 默认 SQLite 或连接 URL。 */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.url', null);
        $app['config']->set('database.connections.pgsql.database', 'novel_forge_app_a03_test');

        return $app;
    }

    /** 在任何清库操作之前校验库名和用途标记。
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        $identity = DB::selectOne("select current_database() as name, shobj_description(oid, 'pg_database') as purpose from pg_database where datname=current_database()");
        if ($identity->name !== 'novel_forge_app_a03_test' || $identity->purpose !== 'novel-forge A03 isolated tests') {
            throw new \RuntimeException('A03 测试库身份不匹配，拒绝迁移');
        }

        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 真正落库的 Fake 输出、定点金额、关联和不可变时间可重新读取。 */
    public function test_fake_result_is_persisted_with_correct_types_and_associations(): void
    {
        Http::preventStrayRequests();
        $stage = NovelStage::factory()->create();
        $log = app(AiTextGenerationService::class)->generate('A03离线验收', novelStageId: $stage->id)->fresh();
        $this->assertSame('succeeded', $log->status);
        $this->assertSame(['ok' => true, 'message' => 'A03 Fake Provider'], $log->structured_output);
        $this->assertSame('0.00000000', $log->actual_cost);
        $this->assertNull($log->input_tokens);
        $this->assertTrue($log->novelStage->is($stage));
        $this->assertTrue($log->novel->is($stage->novel));
        $this->assertInstanceOf(CarbonImmutable::class, $log->finished_at);
        Http::assertNothingSent();
    }

    /** 核对表及每一栏的中文备注，并验证 JSONB、定点金额及时区类型。 */
    public function test_all_columns_have_chinese_comments_and_expected_types(): void
    {
        $table = DB::connection()->getTablePrefix().'cost_logs';
        $columns = DB::select('select a.attname, format_type(a.atttypid, a.atttypmod) as type, col_description(c.oid,a.attnum) as comment, obj_description(c.oid) as table_comment from pg_class c join pg_namespace n on n.oid=c.relnamespace join pg_attribute a on a.attrelid=c.oid where n.nspname=current_schema() and c.relname=? and a.attnum>0 and not a.attisdropped', [$table]);
        $this->assertNotEmpty($columns);
        foreach ($columns as $column) {
            $this->assertMatchesRegularExpression('/\p{Han}/u', $column->comment ?? '', $column->attname);
            $this->assertMatchesRegularExpression('/\p{Han}/u', $column->table_comment ?? '');
            if (in_array($column->attname, ['request_parameters', 'structured_output', 'raw_response', 'price_snapshot'], true)) {
                $this->assertSame('jsonb', $column->type);
            }
            if (in_array($column->attname, ['estimated_cost', 'actual_cost'], true)) {
                $this->assertSame('numeric(20,8)', $column->type);
            }
            if (str_ends_with($column->attname, '_at')) {
                $this->assertStringContainsString('with time zone', $column->type);
            }
        }
    }

    /** 调用标识唯一，孤立关联及删除有历史记录的小说被拒绝。 */
    public function test_unique_request_foreign_keys_and_history_restriction(): void
    {
        $log = CostLog::factory()->create(['novel_id' => Novel::factory()->create()->id]);
        foreach ([['request_uuid' => $log->request_uuid], ['novel_id' => 999999], ['novel_stage_id' => 999999]] as $attributes) {
            try {
                CostLog::factory()->create($attributes);
                $this->fail('约束应拒绝无效数据');
            } catch (QueryException $exception) {
                $this->assertContains($exception->getCode(), ['23505', '23503']);
            }
        }
        try {
            $log->novel->delete();
            $this->fail('有费用历史时禁止级联删除');
        } catch (QueryException $exception) {
            $this->assertSame('23001', $exception->getCode());
        }
    }

    /** 数据库拒绝负数计量、金额和非法模式状态，未知空值允许保存。 */
    public function test_database_rejects_negative_amounts_and_invalid_states(): void
    {
        foreach (['input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'reasoning_tokens', 'estimated_cost', 'actual_cost', 'duration_ms', 'mode', 'status', 'billing_status'] as $column) {
            try {
                CostLog::factory()->create([$column => in_array($column, ['mode', 'status', 'billing_status'], true) ? 'invalid' : -1]);
                $this->fail('数据库必须拒绝非法值：'.$column);
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        }
        $log = CostLog::factory()->create(['actual_cost' => null, 'input_tokens' => null]);
        $this->assertNull($log->fresh()->actual_cost);
    }
}
