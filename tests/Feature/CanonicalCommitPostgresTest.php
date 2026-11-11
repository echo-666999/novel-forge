<?php

namespace Tests\Feature;

use App\Models\CanonicalCommit;
use App\Models\ChapterEventExtraction;
use App\Models\ChapterReview;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterSubmissionValidation;
use App\Models\ForeshadowEvidence;
use App\Models\Novel;
use App\Models\NovelStage;
use App\Models\StoryEntity;
use App\Models\StoryEvent;
use App\Models\StoryOutboxEvent;
use App\Models\StoryStateProjection;
use App\Services\Pipeline\PlanningJson;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/** 固定独立PostgreSQL库验证真实复合约束、中文备注及原子可见性。 */
class CanonicalCommitPostgresTest extends TestCase
{
    use DatabaseMigrations;

    /** 仅在专库身份核验成功后允许清理测试数据。 */
    private bool $identityVerified = false;

    /** 探测只读系统目录；测试写连接只能指向提交专库。 */
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $probe = $app['config']->get('database.connections.pgsql');
        $probe['url'] = null;
        $probe['database'] = 'novel_forge_app';
        $app['config']->set('database.connections.commit_probe', $probe);
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql.url', null);
        $app['config']->set('database.connections.pgsql.database', 'novel_forge_app_commit_test');

        return $app;
    }

    /** 身份、所有者、用途三项全部匹配后才能迁移，未建库明确未完成。 */
    protected function migrateFreshUsing(): array
    {
        $present = DB::connection('commit_probe')->selectOne('select exists(select 1 from pg_database where datname=?) as present', ['novel_forge_app_commit_test']);
        if (! $present->present) {
            $this->markTestIncomplete('管理员尚未创建 novel_forge_app_commit_test，禁止开发库迁移与真实提交准备');
        }
        $identity = DB::selectOne("select current_database() as name, pg_get_userbyid(datdba) as owner, shobj_description(oid, 'pg_database') as purpose from pg_database where datname=current_database()");
        if ($identity->name !== 'novel_forge_app_commit_test' || $identity->owner !== 'novel_forge_app' || $identity->purpose !== 'novel-forge B05 B06 B07 isolated tests') {
            throw new RuntimeException('提交专库身份不符，拒绝迁移');
        }
        $this->identityVerified = true;

        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 不允许外网，实际SDK只使用相同受控响应。 */
    protected function setUp(): void
    {
        parent::setUp();
        ChapterSubmissionTest::fakeProvider();
    }

    /** 只清理已经核实的独立数据库。 */
    protected function tearDown(): void
    {
        if ($this->identityVerified) {
            B04ContentTest::clearFixtures();
        }
        DB::purge('commit_peer');
        DB::purge('commit_probe');
        parent::tearDown();
    }

    /** 每张新表及新增字段都有中文备注；JSONB冻结输入可往返。 */
    public function test_chinese_comments_and_jsonb_roundtrip(): void
    {
        foreach (['search_documents', 'context_manifests', 'foreshadows', 'foreshadow_evidence', 'chapter_submission_runs', 'chapter_reviews', 'chapter_event_extractions', 'chapter_submission_validations', 'canonical_commits', 'story_entities', 'story_events', 'story_state_projections', 'story_outbox_events'] as $table) {
            $name = DB::getTablePrefix().$table;
            $this->assertMatchesRegularExpression('/\p{Han}/u', DB::selectOne('select obj_description(to_regclass(?)) as comment', [$name])->comment);
            foreach (DB::select('select attname,col_description(attrelid,attnum) as comment from pg_attribute where attrelid=to_regclass(?) and attnum>0 and not attisdropped', [$name]) as $column) {
                $this->assertMatchesRegularExpression('/\p{Han}/u', $column->comment, $table.'.'.$column->attname);
            }
        }
        foreach (['formal_version_id', 'canonical_commit_id', 'committed_at'] as $column) {
            $comment = DB::selectOne('select col_description(attrelid,attnum) as comment from pg_attribute where attrelid=to_regclass(?) and attname=?', [DB::getTablePrefix().'chapters', $column]);
            $this->assertMatchesRegularExpression('/\p{Han}/u', $comment->comment);
        }
        [$run] = ChapterSubmissionTest::prepared();
        $this->assertSame('ready', $run->status, $run->stop_reason ?? '');
        $this->assertSame($run->input_hash, PlanningJson::hash([$run->fresh()->snapshot, $run->fresh()->config]));
        $this->assertSame('jsonb', DB::selectOne('select pg_typeof(snapshot)::text as type from '.DB::connection()->getQueryGrammar()->wrapTable('chapter_submission_runs').' where id=?', [$run->id])->type);
    }

    /** 跨章产物、非法计数、错误状态版本和跨小说实体均由数据库拒绝。 */
    public function test_database_constraints_reject_cross_owned_records(): void
    {
        $first = ChapterSubmissionRun::factory()->create();
        $second = ChapterSubmissionRun::factory()->create();
        $review = ChapterReview::factory()->create(['submission_run_id' => $first->id]);
        $this->rejects(fn () => ChapterEventExtraction::factory()->create(['submission_run_id' => $first->id, 'version_id' => $second->version_id]), '23503');
        $this->rejects(fn () => DB::table('chapter_submission_runs')->where('id', $first->id)->update(['requests_used' => 3]), '23514');
        $this->rejects(fn () => ChapterSubmissionValidation::factory()->create(['submission_run_id' => $second->id, 'review_id' => $review->id]), '23503');
        $commit = CanonicalCommit::factory()->create();
        $this->rejects(fn () => DB::table('canonical_commits')->where('id', $commit->id)->update(['state_version' => 3]), '23514');
        $entity = StoryEntity::factory()->create();
        $this->rejects(fn () => StoryEvent::factory()->create(['commit_id' => $commit->id, 'actor_entity_id' => $entity->id]), '23503');
        $this->rejects(fn () => DB::table('chapters')->where('id', $second->chapter_id)->update(['formal_version_id' => $first->version_id]), '23503');
    }

    /** 另一个连接看不到事务中间状态，提交后同时可见计数、事件、投影、发件箱。 */
    public function test_atomic_visibility_and_locking_across_connections(): void
    {
        [$run, $operator] = ChapterSubmissionTest::prepared();
        $peerConfig = config('database.connections.pgsql');
        config(['database.connections.commit_peer' => $peerConfig]);
        $peer = DB::connection('commit_peer');
        StoryOutboxEvent::creating(function () use ($run, $peer): void {
            $this->assertSame(0, $peer->table('canonical_commits')->count());
            $this->assertSame(0, $peer->table('story_events')->count());
            $this->assertSame(0, (int) $peer->table('novels')->where('id', $run->novel_id)->value('state_version'));
            try {
                $peer->transaction(function () use ($peer, $run): void {
                    $peer->statement("set local lock_timeout='100ms'");
                    $peer->table('novels')->where('id', $run->novel_id)->lockForUpdate()->first();
                });
                $this->fail('并发连接未受小说行锁保护');
            } catch (QueryException $exception) {
                $this->assertSame('55P03', $exception->errorInfo[0]);
            }
        });
        try {
            $commit = ChapterSubmissionTest::commit($run, $operator);
        } finally {
            StoryOutboxEvent::flushEventListeners();
        }
        $this->assertSame(1, (int) $peer->table('novels')->where('id', $run->novel_id)->value('state_version'));
        $this->assertSame(1, $peer->table('canonical_commits')->count());
        $this->assertSame(1, $peer->table('story_events')->count());
        $this->assertSame(1, $peer->table('story_state_projections')->count());
        $this->assertSame(1, $peer->table('story_outbox_events')->count());
        $this->assertSame($commit->id, ChapterSubmissionTest::commit($run, $operator, key: $commit->idempotency_key)->id);
        Http::assertSentCount(5);
    }

    /** PostgreSQL也验证事务末端故障回滚，不能只依据SQLite结论。 */
    public function test_atomic_rollback_preserves_candidate_and_state(): void
    {
        [$run, $operator] = ChapterSubmissionTest::prepared();
        $beforeChapter = $run->chapter->getRawOriginal();
        $beforeNovel = $run->novel->getRawOriginal();
        StoryStateProjection::creating(fn () => throw new RuntimeException('隔离投影保存故障'));
        try {
            ChapterSubmissionTest::commit($run, $operator);
            $this->fail('事务故障没有抛出');
        } catch (RuntimeException $exception) {
            $this->assertSame('隔离投影保存故障', $exception->getMessage());
        } finally {
            StoryStateProjection::flushEventListeners();
        }
        $this->assertSame($beforeChapter, $run->chapter->fresh()->getRawOriginal());
        $this->assertSame($beforeNovel, $run->novel->fresh()->getRawOriginal());
        $this->assertSame(0, CanonicalCommit::count());
        $this->assertSame(0, StoryEvent::count());
        $this->assertSame(0, StoryStateProjection::count());
        $this->assertSame(0, StoryOutboxEvent::count());
    }

    /** 增量迁移前后旧业务行逐字段一致，提交准备历史存在时拒绝回滚。 */
    public function test_incremental_migration_preserves_existing_business_rows(): void
    {
        // 前后都读取数据库原始行，避免工厂内存属性缺列及时间格式差异造成误报。
        $stage = NovelStage::factory()->create()->refresh();
        $beforeNovel = $stage->novel->getRawOriginal();
        $beforeStage = $stage->getRawOriginal();
        $migration = require database_path('migrations/2026_11_05_220323_create_chapter_submission_tables.php');
        $lineage = require database_path('migrations/2026_11_06_023001_add_successor_lineage_to_chapter_submission_runs.php');
        // 伏笔证据依赖正式提交，隔离迁移测试先撤销空账本表。
        $foreshadowMigration = require database_path('migrations/2026_11_06_164613_create_foreshadow_ledger_tables.php');
        // C09 实体版本依赖正式实体，专库回滚按子表优先执行，禁止级联删除。
        $entityDefinitionsMigration = require database_path('migrations/2026_11_07_133118_create_entity_definition_versions_table.php');
        $entityDefinitionsMigration->down();
        // D01 伏笔长程契约字段依附于伏笔表，先撤销再撤销原账本表，恢复时按相反顺序重建。
        $foreshadowContractMigration = require database_path('migrations/2026_11_10_201335_add_long_range_contract_to_foreshadows.php');
        $foreshadowContractMigration->down();
        $foreshadowMigration->down();
        $lineage->down();
        $migration->down();
        $migration->up();
        $lineage->up();
        $foreshadowMigration->up();
        $foreshadowContractMigration->up();
        $entityDefinitionsMigration->up();
        $this->assertSame($beforeNovel, Novel::findOrFail($stage->novel_id)->getRawOriginal());
        $this->assertSame($beforeStage, $stage->fresh()->getRawOriginal());
        ChapterSubmissionRun::factory()->create();
        $this->expectExceptionMessage('已有提交准备或正式历史，拒绝回滚');
        $migration->down();
    }

    /** 后继增量迁移不改旧运行；唯一前驱禁止分叉，已有后继禁止回滚。 */
    public function test_successor_migration_preserves_history_and_rejects_branching(): void
    {
        $migration = require database_path('migrations/2026_11_06_023001_add_successor_lineage_to_chapter_submission_runs.php');
        $migration->down();
        $original = ChapterSubmissionRun::factory()->create()->refresh();
        $before = $original->getRawOriginal();
        $migration->up();
        $after = $original->fresh()->getRawOriginal();
        $this->assertSame($before, array_intersect_key($after, $before));
        $this->assertSame(1, $after['attempt']);
        $this->assertNull($after['predecessor_id']);
        $attributes = ['novel_id' => $original->novel_id, 'chapter_id' => $original->chapter_id, 'version_id' => $original->version_id,
            'expected_state_version' => $original->expected_state_version, 'attempt' => 2, 'predecessor_id' => $original->id];
        ChapterSubmissionRun::factory()->create($attributes);
        $this->rejects(fn () => ChapterSubmissionRun::factory()->create([...$attributes, 'attempt' => 3]), '23505');
        $this->expectExceptionMessage('已有后继准备历史，拒绝回滚');
        $migration->down();
    }

    /** 伏笔证据复合外键、转换约束与保留历史的回滚保护必须在真实数据库生效。 */
    public function test_foreshadow_constraints_and_history_protection(): void
    {
        $entry = ForeshadowEvidence::factory()->create();
        $this->rejects(fn () => DB::table('foreshadow_evidence')->where('id', $entry->id)->update(['novel_id' => Novel::factory()->create()->id]), '23503');
        $this->rejects(fn () => DB::table('foreshadow_evidence')->where('id', $entry->id)->update(['to_status' => 'resolved']), '23514');
        $this->rejects(fn () => DB::table('foreshadows')->where('id', $entry->foreshadow_id)->update(['target_chapter' => 0]), '23514');
        $migration = require database_path('migrations/2026_11_06_164613_create_foreshadow_ledger_tables.php');
        $this->expectExceptionMessage('伏笔账本已有历史，拒绝回滚');
        $migration->down();
    }

    /** 约束失败独立回滚，避免PostgreSQL事务错误污染后续断言。 */
    private function rejects(callable $operation, string $state): void
    {
        try {
            DB::transaction($operation);
            $this->fail('数据库未拒绝非法写入');
        } catch (QueryException $exception) {
            $this->assertSame($state, $exception->errorInfo[0]);
        }
    }
}
