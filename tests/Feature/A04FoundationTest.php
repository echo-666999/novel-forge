<?php

namespace Tests\Feature;

use App\Enums\ChapterStatus;
use App\Enums\FactCategory;
use App\Enums\NovelStatus;
use App\Enums\Stage;
use App\Enums\StageStatus;
use App\Models\Chapter;
use App\Models\Novel;
use App\Models\NovelStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 在专用 PostgreSQL 数据库验证 A04，不重置开发库或既有测试库。 */
class A04FoundationTest extends TestCase
{
    use DatabaseMigrations;

    /** 启动测试应用并固定独立数据库，忽略默认 SQLite 和连接 URL。 */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.url', null);
        $app['config']->set('database.connections.pgsql.database', 'novel_forge_app_a04_test');

        return $app;
    }

    /** 仅执行本应用迁移，避免触碰 A02 第三方扩展验证。 */
    protected function migrateFreshUsing(): array
    {
        // 清库之前再次核实库名与用途标记，防止误连现有业务数据库。
        $database = DB::selectOne('select current_database() as name, shobj_description(oid, \'pg_database\') as purpose from pg_database where datname = current_database()');
        if ($database->name !== 'novel_forge_app_a04_test' || $database->purpose !== 'novel-forge A04 isolated tests') {
            throw new \RuntimeException('A04 测试数据库身份校验失败，禁止执行迁移');
        }

        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 验证阶段顺序与六类事实的稳定存储值及中文标签。 */
    public function test_enums_preserve_the_fixed_stages_and_fact_categories(): void
    {
        $this->assertSame(['setting', 'outline', 'fine_outline', 'content', 'polish', 'export'], array_column(Stage::cases(), 'value'));
        $this->assertSame(['objective_confirmed', 'character_belief', 'rumor', 'inference', 'lie', 'author_hidden_truth'], array_column(FactCategory::cases(), 'value'));
        foreach ([Stage::class, NovelStatus::class, StageStatus::class, ChapterStatus::class, FactCategory::class] as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertSame($case, $enum::from($case->value));
                $this->assertMatchesRegularExpression('/\p{Han}/u', $case->label());
            }
        }
    }

    /** 验证双向关联、默认值、结构化输入输出与不可变时间转换。 */
    public function test_models_persist_relations_and_typed_snapshots(): void
    {
        $novel = Novel::factory()->create()->refresh();
        $stage = NovelStage::factory()->for($novel)->create([
            'input' => ['创意' => '寻找归途'],
            'output' => ['人物' => ['主角']],
            'started_at' => now(),
        ])->refresh();
        $chapter = Chapter::factory()->for($novel)->create()->refresh();
        $repeat = NovelStage::factory()->for($novel)->create();

        $this->assertTrue($novel->stages->contains($stage));
        $this->assertTrue($novel->stages->contains($repeat));
        $this->assertTrue($novel->chapters->contains($chapter));
        $this->assertTrue($stage->novel->is($novel));
        $this->assertTrue($chapter->novel->is($novel));
        $this->assertSame(NovelStatus::Pending, $novel->status);
        $this->assertNull($novel->current_stage);
        $this->assertSame(0, $novel->execution_count);
        $this->assertSame(5, $novel->max_execution_count);
        $this->assertSame('0.00', $novel->progress);
        $this->assertSame(0, $novel->chapters_total);
        $this->assertSame(0, $novel->state_version);
        // JSONB 对象键序不构成语义，嵌套列表仍由原有断言保持顺序校验。
        $this->assertEquals(['min_characters' => 2000, 'max_characters' => 3000], $novel->chapter_length_policy);
        $this->assertSame(Stage::Setting, $stage->stage);
        $this->assertSame(StageStatus::Pending, $stage->status);
        $this->assertSame(['创意' => '寻找归途'], $stage->input);
        $this->assertSame(['人物' => ['主角']], $stage->output);
        $this->assertSame(ChapterStatus::Pending, $chapter->status);
        $this->assertInstanceOf(CarbonImmutable::class, $stage->started_at);
        $this->assertInstanceOf(CarbonImmutable::class, $novel->created_at);
    }

    /** 同一本书章号必须唯一，但不同小说可以使用相同章号。 */
    public function test_chapter_numbers_are_unique_within_each_novel(): void
    {
        $chapter = Chapter::factory()->create();
        Chapter::factory()->create(['number' => $chapter->number]);

        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23505');
        Chapter::factory()->for($chapter->novel)->create(['number' => $chapter->number]);
    }

    /** 阶段记录必须关联真实小说。 */
    public function test_orphan_stage_records_are_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        NovelStage::factory()->create(['novel_id' => 9223372036854775807]);
    }

    /** 两种子记录分别阻止直接删除小说，保留已有执行历史。 */
    public function test_deleting_novels_with_children_is_restricted(): void
    {
        foreach ([NovelStage::class, Chapter::class] as $model) {
            $child = $model::factory()->create();
            try {
                $child->novel->delete();
                $this->fail('含有关联记录的小说不应被删除');
            } catch (QueryException $exception) {
                // RESTRICT 删除返回 restriction_violation，区别于新增孤立外键的 23503。
                $this->assertSame('23001', $exception->getCode());
                $this->assertDatabaseHas('novels', ['id' => $child->novel_id]);
            }
        }
    }

    /** 在真实主库类型上验证计数、章号、目标和进度边界。 */
    public function test_database_checks_reject_invalid_numeric_values(): void
    {
        $novel = Novel::factory()->create();
        $invalid = [
            [Novel::class, ['target_chapters' => 0]],
            [Novel::class, ['execution_count' => -1]],
            [Novel::class, ['max_execution_count' => 0]],
            [Novel::class, ['progress' => -0.01]],
            [Novel::class, ['progress' => 100.01]],
            [Novel::class, ['chapters_done' => -1]],
            [Novel::class, ['chapters_total' => -1]],
            [Novel::class, ['state_version' => -1]],
            [NovelStage::class, ['novel_id' => $novel->id, 'execution_count' => -1]],
            [Chapter::class, ['novel_id' => $novel->id, 'number' => 0]],
        ];
        foreach ($invalid as [$model, $attributes]) {
            try {
                $model::factory()->create($attributes);
                $this->fail('数据库应拒绝非法数值');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        }
        $novel->update(['progress' => 100]);
        $this->assertSame('100.00', $novel->refresh()->progress);
    }

    /** 逐字段核实 PostgreSQL 中的中文备注，以及 JSONB 和时区时间类型。 */
    public function test_tables_and_every_column_have_chinese_comments_and_expected_types(): void
    {
        foreach (['novels', 'novel_stages', 'chapters'] as $table) {
            $name = DB::connection()->getTablePrefix().$table;
            $columns = DB::select('select a.attname, format_type(a.atttypid, a.atttypmod) as type, col_description(c.oid, a.attnum) as comment, obj_description(c.oid) as table_comment from pg_class c join pg_namespace n on n.oid = c.relnamespace join pg_attribute a on a.attrelid = c.oid where n.nspname = current_schema() and c.relname = ? and a.attnum > 0 and not a.attisdropped', [$name]);
            $this->assertNotEmpty($columns);
            foreach ($columns as $column) {
                $this->assertMatchesRegularExpression('/\p{Han}/u', $column->comment ?? '', $name.'.'.$column->attname);
                $this->assertMatchesRegularExpression('/\p{Han}/u', $column->table_comment ?? '');
                if (in_array($column->attname, ['input', 'output', 'chapter_length_policy'])) {
                    $this->assertSame('jsonb', $column->type);
                }
                if (in_array($column->attname, ['created_at', 'updated_at', 'started_at', 'finished_at'])) {
                    $this->assertStringContainsString('with time zone', $column->type);
                }
            }
        }
    }
}
