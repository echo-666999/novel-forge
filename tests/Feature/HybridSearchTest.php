<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\Pages\SearchLab;
use App\Models\CanonicalCommit;
use App\Models\Chapter;
use App\Models\ChapterGenerationRun;
use App\Models\ChapterSubmissionRun;
use App\Models\ChapterSubmissionValidation;
use App\Models\ChapterVersion;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\StoryEntity;
use App\Models\StoryOutboxEvent;
use App\Models\StoryStateProjection;
use App\Models\User;
use App\Services\Pipeline\PlanningJson;
use App\Services\Search\ChapterSearchIndex;
use App\Services\Search\EmbeddingService;
use App\Services\Search\HybridSearch;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** 专库验证四路中文检索和隔离，受控HTTP不产生真实费用。 */
class HybridSearchTest extends TestCase
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
        if ((int) DB::selectOne("select count(*) as n from pg_extension where extname in ('vector','pg_trgm','zhparser')")->n !== 3) {
            $this->markTestIncomplete('C05专库尚未配置vector、pg_trgm、zhparser，未执行检索验收');
        }
        $this->identityVerified = true;

        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 不允许外网，实际SDK只使用相同受控响应。 */
    protected function setUp(): void
    {
        parent::setUp();
        // 只替换受控正文响应的前缀，保留长度及真实生成、评审、提交路径。
        Http::swap(new Factory);
        Http::fake(function (Request $request) {
            if ((CostLog::latest('id')->first()?->request_parameters['step'] ?? null) !== 'draft') {
                return null;
            }
            $response = B04ContentTest::validResponse($request);
            $data = json_decode($response['output'][0]['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);
            foreach ($data['paragraphs'] as &$paragraph) {
                $prefix = '林澈走进档案室。';
                $paragraph[0]['text'] = $prefix.mb_substr($paragraph[0]['text'], mb_strlen($prefix), -1).'。';
            }
            unset($paragraph);
            $response['output'][0]['content'][0]['text'] = PlanningJson::encode($data);

            return Http::response($response);
        });
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

    /** 使用真实提交路径创建来源；所有embedding HTTP受控，禁止访问外网。
     * @return array{Novel, User}
     */
    private function source(): array
    {
        [$run, $operator] = ChapterSubmissionTest::prepared();
        $commit = ChapterSubmissionTest::commit($run, $operator);
        // 受控正文夹具含“档案室”；工厂补齐同章实体目录，专门验证名称与别名通路。
        $this->assertStringContainsString('档案室', ChapterVersion::findOrFail($run->version_id)->body);
        $definition = ['name' => '档案室', 'type' => 'location', 'aliases' => ['档案馆'], 'description' => '仅专库检索夹具'];
        StoryEntity::factory()->create(['novel_id' => $run->novel_id, 'name' => $definition['name'], 'normalized_name' => $definition['name'], 'aliases' => $definition['aliases'], 'definition' => $definition,
            'definition_hash' => PlanningJson::hash($definition), 'origin' => ['kind' => 'chapter_commit', 'commit_id' => $commit->id], 'approved_by' => $operator->id]);

        return [Novel::findOrFail($run->novel_id), $operator];
    }

    /** 不同输入使用受控非零向量，测试只证明SQL通路，不冒充语义质量。 */
    private function fakeEmbeddings(): void
    {
        // 清除正文夹具的通配响应，避免它抢先拦截嵌入请求；继续禁止任何外网。
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/embeddings' => fn ($request) => Http::response(['model' => EmbeddingService::MODEL, 'data' => array_map(fn ($index) => ['index' => $index, 'embedding' => [1.0, ...array_fill(0, 1535, 0.0)]], array_keys($request['input'])), 'usage' => ['prompt_tokens' => 12]], 200, ['x-request-id' => 'c05-controlled'])]);
    }

    /** 真实全文、别名、模糊和向量均可召回；另一小说同词不能串入。 */
    public function test_four_channels_scope_cache_and_ui(): void
    {
        [$novel, $operator] = $this->source();
        [$other] = $this->source();
        $this->fakeEmbeddings();
        $index = app(ChapterSearchIndex::class);
        $first = $index->build($novel, true);
        $index->build($other, true);
        $calls = CostLog::where('route_version', EmbeddingService::PROFILE)->count();
        $this->assertSame(0, $first['pending']);
        $this->assertSame($first, $index->build($novel, true));
        $this->assertSame($calls, CostLog::where('route_version', EmbeddingService::PROFILE)->count());
        $body = DB::table('search_documents')->where('novel_id', $novel->id)->value('body');
        // 从实际正式夹具选取已出现的实体词，不凭空设定正文事实。
        $names = json_decode(DB::table('search_documents')->where('novel_id', $novel->id)->where('entity_text', '<>', '')->value('entity_names'), true);
        $query = $names[0];
        $result = app(HybridSearch::class)->search($novel, $query, 1, true);
        foreach (['exact', 'fulltext', 'fuzzy', 'vector'] as $channel) {
            $this->assertGreaterThan(0, $result['channel_counts'][$channel], $query.' '.$channel);
        }
        $this->assertNull($result['warning']);
        $alias = app(HybridSearch::class)->search($novel, '档案馆', 1);
        $this->assertGreaterThan(0, $alias['channel_counts']['exact']);
        $this->assertSame([$novel->id], array_unique(array_column($result['results'], 'novel_id')));
        $this->assertSame([1], array_unique(array_column($result['results'], 'chapter')));
        $calls = CostLog::where('route_version', EmbeddingService::PROFILE)->count();
        app(HybridSearch::class)->search($novel, $query, 1, true);
        $this->assertSame($calls, CostLog::where('route_version', EmbeddingService::PROFILE)->count());
        $this->actingAs($operator);
        Livewire::test(SearchLab::class, ['record' => $novel->id])->set('query', $query)->set('throughChapter', 1)->set('semantic', true)->call('search')->assertHasNoErrors()->assertSee('正式版本')->assertSee('实体精确');
        $this->assertSame($calls, CostLog::where('route_version', EmbeddingService::PROFILE)->count());
        $this->assertNotEmpty($body);
        $this->assertSame(2, StoryOutboxEvent::whereNull('published_at')->count());
    }

    /** 未完成向量明确降级；失败只记录一次请求，后续词法仍能使用。 */
    public function test_provider_failure_degrades_without_implicit_retry(): void
    {
        [$novel] = $this->source();
        app(ChapterSearchIndex::class)->build($novel);
        // 清除正文夹具的通配响应，避免它抢先拦截嵌入请求；继续禁止任何外网。
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/v1/embeddings' => Http::response(['error' => 'unavailable'], 503)]);
        try {
            app(ChapterSearchIndex::class)->build($novel, true);
            $this->fail('失败请求不得宣称完成');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('嵌入未完成', $exception->getMessage());
        }
        $this->assertSame(1, CostLog::where('route_version', EmbeddingService::PROFILE)->count());
        $this->assertSame('unknown', CostLog::where('route_version', EmbeddingService::PROFILE)->sole()->status);
        $this->assertSame('http_503', CostLog::where('route_version', EmbeddingService::PROFILE)->sole()->error_category);
        Http::assertSentCount(1);
        $result = app(HybridSearch::class)->search($novel, '观察', 1, true);
        $this->assertStringContainsString('降级', $result['warning']);
        $this->assertArrayNotHasKey('vector', $result['channel_counts']);
    }

    /** 两章均可命中时，四路召回必须在排名前排除未来章。 */
    public function test_chapter_cutoff_and_stale_formal_pointer(): void
    {
        [$novel] = $this->source();
        // 工厂仅补充第二章的隔离关系，不伪称真实生成或文学验收。
        $chapter = Chapter::factory()->create(['novel_id' => $novel->id, 'number' => 2]);
        $run = ChapterGenerationRun::factory()->create(['chapter_id' => $chapter->id]);
        $body = '林澈回到档案室，发现一封密信。';
        $version = ChapterVersion::factory()->create(['run_id' => $run->id, 'body' => $body, 'body_hash' => hash('sha256', $body)]);
        $submission = ChapterSubmissionRun::factory()->create(['version_id' => $version->id]);
        $validation = ChapterSubmissionValidation::factory()->create(['submission_run_id' => $submission->id]);
        $commit = CanonicalCommit::factory()->create(['validation_id' => $validation->id, 'previous_state_version' => 1, 'state_version' => 2, 'result' => ['body_hash' => $version->body_hash]]);
        $chapter->update(['formal_version_id' => $version->id, 'canonical_commit_id' => $commit->id]);
        StoryStateProjection::factory()->create(['commit_id' => $commit->id]);
        StoryOutboxEvent::factory()->create(['commit_id' => $commit->id]);
        $this->fakeEmbeddings();
        app(ChapterSearchIndex::class)->build($novel, true);
        $search = app(HybridSearch::class);
        $all = $search->search($novel, '档案室', 2, true);
        $this->assertEqualsCanonicalizing([1, 2], array_column($all['results'], 'chapter'));
        $earlier = $search->search($novel, '档案室', 1, true);
        $this->assertSame([1], array_column($earlier['results'], 'chapter'));
        foreach (['exact', 'fulltext', 'fuzzy', 'vector'] as $channel) {
            $this->assertGreaterThan(0, $earlier['channel_counts'][$channel]);
            $this->assertLessThan($all['channel_counts'][$channel], $earlier['channel_counts'][$channel]);
        }
        // 真实Livewire状态从全范围切到前一章：先清空旧结果，再按新范围执行。
        $this->actingAs(User::findOrFail($commit->committed_by));
        $page = Livewire::test(SearchLab::class, ['record' => $novel->id])
            ->assertSet('throughChapter', null)->set('query', '档案室')->set('semantic', true)
            ->call('search')->assertHasErrors(['throughChapter'])->assertSet('result', null)
            ->set('throughChapter', 2)->call('search')->assertHasNoErrors()->assertSet('result.through_chapter', 2);
        $this->assertCount(2, $page->get('result')['results']);
        $page->set('throughChapter', 1)->assertSet('result', null)->call('search')->assertHasNoErrors()->assertSet('result.through_chapter', 1);
        $this->assertSame([1], array_column($page->get('result')['results'], 'chapter'));
        $page->set('throughChapter', 10)->call('search')->assertHasErrors(['throughChapter'])->assertSet('result', null);
        // 失去正式指针的来源即使向量还在，也不能继续被召回。
        $chapter->update(['formal_version_id' => null, 'canonical_commit_id' => null]);
        $this->assertSame([1], array_column($search->search($novel, '档案室', 2, true)['results'], 'chapter'));
    }

    /** 已确认可命中的块被篡改时拒绝展示，不能把投影当作原文事实。 */
    public function test_tampered_source_is_rejected(): void
    {
        [$novel] = $this->source();
        app(ChapterSearchIndex::class)->build($novel);
        $search = app(HybridSearch::class);
        $this->assertNotEmpty($search->search($novel, '档案室', 1)['results']);
        DB::table('search_documents')->where('novel_id', $novel->id)->update(['body' => '档案室的伪造证据']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('检索投影与正式原文不一致');
        $search->search($novel, '档案室', 1);
    }

    /** 作者视角索引不得用于缺少角色知情证据的POV请求。 */
    public function test_pov_request_is_rejected_without_embedding(): void
    {
        [$novel] = $this->source();
        $this->fakeEmbeddings();
        try {
            app(HybridSearch::class)->search($novel, '档案室', 1, true, povEntityId: 1);
            $this->fail('没有角色知情映射时必须拒绝');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('禁止用于POV', $exception->getMessage());
        }
        Http::assertNothingSent();
    }
}
