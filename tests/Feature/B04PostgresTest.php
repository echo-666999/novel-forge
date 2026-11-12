<?php

namespace Tests\Feature;

use App\Jobs\RunChapterStepJob;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterProgressionCheck;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Models\User;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\PlanningJson;
use App\Services\Pipeline\ProgressionChecker;
use App\Settings\GeneralSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** 专库验证PostgreSQL约束、中文备注、JSONB及并发可见性，不调用真实模型。 */
class B04PostgresTest extends TestCase
{
    use DatabaseMigrations;

    private bool $identityVerified = false;

    /** 探测连接只读开发库目录，业务测试始终固定到B04专库。 */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $probe = $app['config']->get('database.connections.pgsql');
        $probe['url'] = null;
        $probe['database'] = 'novel_forge_app';
        $app['config']->set('database.connections.b04_probe', $probe);
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.url', null);
        $app['config']->set('database.connections.pgsql.database', 'novel_forge_app_b04_test');

        return $app;
    }

    /** 必须先验证库名、所有者和用途，才能执行任何迁移或清理。 */
    protected function migrateFreshUsing(): array
    {
        $present = DB::connection('b04_probe')->selectOne('select exists(select 1 from pg_database where datname=?) as present', ['novel_forge_app_b04_test']);
        if (! $present->present) {
            $this->markTestIncomplete('管理员尚未创建 novel_forge_app_b04_test，B04专项不能宣称通过');
        }
        $identity = DB::selectOne("select current_database() as name, pg_get_userbyid(datdba) as owner, shobj_description(oid, 'pg_database') as purpose from pg_database where datname=current_database()");
        if ($identity->name !== 'novel_forge_app_b04_test' || $identity->owner !== 'novel_forge_app' || $identity->purpose !== 'novel-forge B04 isolated tests') {
            throw new RuntimeException('B04专库身份不符，拒绝迁移');
        }
        $this->identityVerified = true;

        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 模型请求只能使用协议夹具。 */
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['ai.providers.openai.key' => 'sk-b04-pg-test', 'ai.providers.openai.url' => 'https://api.openai.com/v1']);
    }

    /** 仅在已核实的专库逆序清理测试数据。 */
    protected function tearDown(): void
    {
        if ($this->identityVerified) {
            B04ContentTest::clearFixtures();
        }
        DB::purge('b04_peer');
        DB::purge('b04_probe');
        parent::tearDown();
    }

    /** 所有新表字段及既有表新增字段均有中文备注，JSONB往返摘要一致。 */
    public function test_comments_and_jsonb_hash_roundtrip(): void
    {
        foreach (['chapter_generation_runs', 'chapter_versions', 'chapter_progression_checks'] as $table) {
            $this->assertMatchesRegularExpression('/\p{Han}/u', DB::selectOne('select obj_description(to_regclass(?)) as comment', [DB::getTablePrefix().$table])->comment);
            $columns = DB::select('select a.attname, col_description(a.attrelid,a.attnum) as comment from pg_attribute a where a.attrelid=to_regclass(?) and a.attnum>0 and not a.attisdropped', [DB::getTablePrefix().$table]);
            foreach ($columns as $column) {
                $this->assertMatchesRegularExpression('/\p{Han}/u', $column->comment, $table.'.'.$column->attname);
            }
        }
        foreach (['current_candidate_version_id', 'progression_result'] as $name) {
            $comment = DB::selectOne('select col_description(attrelid,attnum) as comment from pg_attribute where attrelid=to_regclass(?) and attname=?', [DB::getTablePrefix().'chapters', $name]);
            $this->assertMatchesRegularExpression('/\p{Han}/u', $comment->comment);
        }
        $run = app(ContentGenerationService::class)->start(B04ContentTest::readyNovel(), User::firstOrFail());
        $this->assertSame($run->input_hash, PlanningJson::hash([$run->fresh()->snapshot, $run->fresh()->config]));
        $this->assertSame('jsonb', DB::selectOne('select pg_typeof(snapshot)::text as type from '.DB::connection()->getQueryGrammar()->wrapTable('chapter_generation_runs').' where id=?', [$run->id])->type);
    }

    /** 复合外键拒绝跨章候选和检查，数据库层拒绝重号及非法计数。 */
    public function test_constraints_reject_cross_chapter_and_duplicate_versions(): void
    {
        $a = ChapterVersion::factory()->create();
        $b = ChapterVersion::factory()->create();
        $this->rejects(fn () => DB::table('chapters')->where('id', $a->chapter_id)->update(['current_candidate_version_id' => $b->id]), '23503');
        $this->rejects(fn () => ChapterProgressionCheck::factory()->create(['version_id' => $a->id, 'chapter_id' => $b->chapter_id]), '23503');
        $this->rejects(fn () => ChapterVersion::factory()->create(['run_id' => $a->run_id, 'chapter_id' => $a->chapter_id, 'version' => 1]), '23505');
        $this->rejects(fn () => ChapterGenerationRun::factory()->create(['chapter_id' => $a->chapter_id, 'outline_chapter_id' => ChapterGenerationRun::findOrFail($a->run_id)->outline_chapter_id, 'parent_run_id' => $a->run_id, 'source_version_id' => $b->id, 'status' => 'paused']), '23503');
        $this->rejects(fn () => ChapterGenerationRun::factory()->create(['chapter_id' => $a->chapter_id, 'outline_chapter_id' => ChapterGenerationRun::findOrFail($a->run_id)->outline_chapter_id, 'parent_run_id' => $a->run_id, 'source_version_id' => $a->id]), '23505');
        $this->rejects(fn () => DB::table('chapter_generation_runs')->where('id', $a->run_id)->update(['length_repair_count' => 3]), '23514');
        $this->rejects(fn () => DB::table('chapter_generation_runs')->where('id', $a->run_id)->update(['requests_used' => -1]), '23514');
        $this->rejects(fn () => DB::table('chapter_generation_runs')->where('id', $a->run_id)->update(['requests_used' => 4, 'request_limit' => 3]), '23514');
    }

    /** 外部请求开始前，另一连接已经看见领用与费用；重复消息不能再发送。 */
    public function test_committed_claim_and_cost_are_visible_to_peer(): void
    {
        $run = app(ContentGenerationService::class)->start(B04ContentTest::readyNovel(), User::firstOrFail());
        config(['database.connections.b04_peer' => config('database.connections.pgsql')]);
        $job = Queue::pushed(RunChapterStepJob::class)->sole();
        Http::fake(['*' => function () use ($run, $job) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('running', DB::connection('b04_peer')->table('chapter_generation_runs')->where('id', $run->id)->value('status'));
            $this->assertSame('pending', DB::connection('b04_peer')->table('cost_logs')->latest('id')->value('status'));
            $job->handle(app(ContentGenerationService::class), app(AiTextGenerationService::class), app(ProgressionChecker::class));

            return Http::response(B02SettingTest::response(PlanningJson::encode(B04ContentTest::fine())));
        }]);
        $job->handle(app(ContentGenerationService::class), app(AiTextGenerationService::class), app(ProgressionChecker::class));
        $this->assertSame(1, $run->fresh()->requests_used);
        $this->assertNotNull($run->fresh()->fine_stage_id);
        $this->assertSame(1, CostLog::count());
        Http::assertSentCount(1);
    }

    /** 空表增量上下迁移不改变已有小说和历史阶段。 */
    public function test_incremental_migration_preserves_existing_rows(): void
    {
        $stage = NovelStage::factory()->create();
        $before = $stage->novel->fresh()->getRawOriginal();
        $stageBefore = $stage->fresh()->getRawOriginal();
        $migration = require database_path('migrations/2026_11_03_214251_create_b04_content_tables.php');
        $recoveryMigration = require database_path('migrations/2026_11_04_011317_add_b04_length_recovery_to_chapter_generation_runs.php');
        $submissionMigration = require database_path('migrations/2026_11_05_220323_create_chapter_submission_tables.php');
        // 后继谱系依赖提交表，按迁移逆序撤销并在恢复时重建。
        $lineageMigration = require database_path('migrations/2026_11_06_023001_add_successor_lineage_to_chapter_submission_runs.php');
        // 伏笔证据依赖正式提交，隔离迁移测试先撤销空账本表。
        $foreshadowMigration = require database_path('migrations/2026_11_06_164613_create_foreshadow_ledger_tables.php');
        // C09 阅读证据引用正文版本，先撤销空阅读表再验证旧迁移。
        $readingMigration = require database_path('migrations/2026_11_07_014131_create_reading_records_table.php');
        // A-09 基线与正式稿修订依赖正式提交、正文版本与伏笔证据，最先撤销、最后重建。
        $formalRevisionMigration = require database_path('migrations/2026_11_12_140653_create_formal_revisions_table.php');
        $formalRevisionMigration->down();
        $baselineMigration = require database_path('migrations/2026_11_12_133335_create_canonical_baselines_table.php');
        $baselineMigration->down();
        $readingMigration->down();
        // C09 实体版本依赖正式实体，专库回滚按子表优先执行，禁止级联删除。
        $entityDefinitionsMigration = require database_path('migrations/2026_11_07_133118_create_entity_definition_versions_table.php');
        $entityDefinitionsMigration->down();
        // D01 伏笔长程契约字段依附于伏笔表，先撤销再撤销原账本表，恢复时按相反顺序重建。
        $foreshadowContractMigration = require database_path('migrations/2026_11_10_201335_add_long_range_contract_to_foreshadows.php');
        $foreshadowContractMigration->down();
        $foreshadowMigration->down();
        $lineageMigration->down();
        $submissionMigration->down();
        $recoveryMigration->down();
        $migration->down();
        $migration->up();
        $recoveryMigration->up();
        $submissionMigration->up();
        $lineageMigration->up();
        $foreshadowMigration->up();
        $foreshadowContractMigration->up();
        $entityDefinitionsMigration->up();
        $readingMigration->up();
        $baselineMigration->up();
        $formalRevisionMigration->up();
        $this->assertSame($before, Novel::findOrFail($stage->novel_id)->getRawOriginal());
        $this->assertSame($stageBefore, $stage->fresh()->getRawOriginal());
    }

    /** 恢复增量迁移上下执行不能改写原v1运行及正文。 */
    public function test_recovery_migration_preserves_old_run_and_body(): void
    {
        $version = ChapterVersion::factory()->create();
        $run = ChapterGenerationRun::findOrFail($version->run_id);
        $beforeRun = $run->fresh()->getRawOriginal();
        unset($beforeRun['parent_run_id'], $beforeRun['source_version_id'], $beforeRun['length_repair_count']);
        $beforeBody = $version->fresh()->getRawOriginal();
        $migration = require database_path('migrations/2026_11_04_011317_add_b04_length_recovery_to_chapter_generation_runs.php');
        $migration->down();
        $migration->up();
        $after = $run->fresh()->getRawOriginal();
        unset($after['parent_run_id'], $after['source_version_id'], $after['length_repair_count']);
        $this->assertSame($beforeRun, $after);
        $this->assertSame($beforeBody, $version->fresh()->getRawOriginal());
    }

    /** 已有付费运行或候选时，直接回滚必须拒绝。 */
    public function test_migration_refuses_to_delete_existing_history(): void
    {
        ChapterGenerationRun::factory()->create();
        $migration = require database_path('migrations/2026_11_03_214251_create_b04_content_tables.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    /** 新默认入口经过真实JSONB往返，检查与阅读回执仍绑定同一正文。 */
    public function test_v14_generation_and_reading_survive_jsonb_roundtrip(): void
    {
        Http::fake(['*' => fn ($request) => Http::response(B04ContentTest::validResponse($request))]);
        $novel = B04ContentTest::readyNovel();
        $novel->update(['chapter_length_policy' => ['min_characters' => 2500, 'max_characters' => 3500]]);
        $settings = app(GeneralSettings::class);
        $settings->temperature = 0.0;
        $settings->save();
        $run = app(ContentGenerationService::class)->start($novel, User::firstOrFail());
        $this->drain();
        $this->assertSame('awaiting_review', $run->fresh()->stop_reason);
        $version = ChapterVersion::sole();
        $check = ChapterProgressionCheck::sole();
        $this->assertSame($version->id, $check->version_id);
        $this->assertSame($version->body_hash, $check->body_hash);
        $this->assertSame($run->input_hash, PlanningJson::hash([$run->fresh()->snapshot, $run->fresh()->config]));
        $receipt = app(ContentGenerationService::class)->recordReading($run->id, User::firstOrFail(), true, ['隔离数据库全文验收']);
        $this->assertSame('b04_accepted', $receipt->stop_reason);
        $this->assertFalse($receipt->control_history[array_key_last($receipt->control_history)]['author_story_approval']);
        $this->assertSame(0, $novel->fresh()->state_version);
        Http::assertSentCount(3);
    }

    /** 一次修订耗尽后仍需新授权；多个全文意见在JSONB排序后完整传递。 */
    public function test_bounded_revision_and_multiple_findings_in_postgres(): void
    {
        $checks = 0;
        Http::fake(['*' => function ($request) use (&$checks) {
            $step = CostLog::latest('id')->firstOrFail()->request_parameters['step'];
            $met = $step !== 'check' || ++$checks > 1;

            return Http::response(B04ContentTest::validResponse($request, $met));
        }]);
        $service = app(ContentGenerationService::class);
        $run = $service->start(B04ContentTest::readyNovel(), User::firstOrFail());
        $this->drain();
        $this->assertSame(5, $run->fresh()->requests_used);
        $this->assertSame(1, $run->fresh()->retry_count);
        $service->recordReading($run->id, User::firstOrFail(), false, ['隔离意见甲', '隔离意见乙']);
        $before = $run->fresh()->getRawOriginal();
        $oldVersions = ChapterVersion::orderBy('id')->get()->map->getRawOriginal()->all();
        $next = $service->recover($run->id, User::firstOrFail(), '另行明确授权两次有限请求');
        $this->assertSame(2, $next->request_limit);
        $this->drain(5);
        $this->assertSame('awaiting_review', $next->fresh()->stop_reason);
        $reviews = ChapterProgressionCheck::latest('id')->firstOrFail()->result['reading_reviews'];
        $this->assertCount(2, $reviews);
        $this->assertSame($before, $run->fresh()->getRawOriginal());
        $this->assertSame($oldVersions, ChapterVersion::orderBy('id')->limit(2)->get()->map->getRawOriginal()->all());
        Http::assertSentCount(7);
    }

    /** 冻结旧协议配置只读接纳后统一恢复，旧消息不再消费外部请求。 */
    public function test_frozen_legacy_candidate_recovers_to_v14_in_postgres(): void
    {
        Http::fake(['*' => fn ($request) => Http::response(B04ContentTest::validResponse($request))]);
        $service = app(ContentGenerationService::class);
        $parent = $service->start(B04ContentTest::readyNovel(), User::firstOrFail());
        $this->drain();
        $config = json_decode(file_get_contents(base_path('tests/Fixtures/b04-legacy-v2-config.json')), true, flags: JSON_THROW_ON_ERROR);
        DB::table('chapter_generation_runs')->where('id', $parent->id)->update(['config' => PlanningJson::encode($config), 'input_hash' => PlanningJson::hash([$parent->snapshot, $config])]);
        $parent->refresh();
        $service->assertFrozenSources($parent);
        $before = $parent->getRawOriginal();
        $service->runStep($parent->id, $parent->invocation_id, app(AiTextGenerationService::class), app(ProgressionChecker::class));
        $next = $service->recover($parent->id, User::firstOrFail(), '隔离库旧候选有限恢复');
        $this->drain(3);
        $this->assertSame('b04-content-v14', $next->config['version']);
        $this->assertSame('awaiting_review', $next->fresh()->stop_reason);
        $this->assertSame($before, $parent->fresh()->getRawOriginal());
        $this->assertSame($parent->fine_stage_id, $next->fine_stage_id);
        Http::assertSentCount(5);
    }

    /** 按已消费偏移处理专用队列夹具，不能意外执行无限循环。 */
    private function drain(int $offset = 0): void
    {
        for ($index = $offset; $index < $offset + 8; $index++) {
            $job = Queue::pushed(RunChapterStepJob::class)->get($index);
            if ($job === null) {
                return;
            }
            $job->handle(app(ContentGenerationService::class), app(AiTextGenerationService::class), app(ProgressionChecker::class));
        }
        $this->fail('数据库专项出现意外无限流程');
    }

    /** 每次预期SQL错误独立回滚，避免污染后续断言。 */
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
