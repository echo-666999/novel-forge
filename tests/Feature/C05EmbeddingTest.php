<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\SearchLab;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Models\CostLog;
use App\Models\FirstChapterRun;
use App\Models\Novel;
use App\Models\User;
use App\Services\Search\EmbeddingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/** 嵌入请求计数、缓存隔离与异常响应证据验收，不访问真实供应商。 */
class C05EmbeddingTest extends TestCase
{
    use DatabaseMigrations;

    /** 内存测试库执行全部业务迁移。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 隔离网络、队列及后台账号。 */
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** 不可变历史只在内存测试库清理。 */
    protected function tearDown(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        // 只在已核验的内存库移除自动编排测试授权，生产历史不受影响。
        DB::table('first_chapter_runs')->delete();
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 自动编排白名单必须保留检索入口，普通小说也可访问同一路由。 */
    public function test_search_link_is_visible_for_manual_and_automated_novels(): void
    {
        foreach ([false, true] as $automated) {
            $novel = Novel::factory()->create();
            if ($automated) {
                FirstChapterRun::factory()->create(['novel_id' => $novel->id]);
            }
            $url = NovelResource::getUrl('search', ['record' => $novel->id]);
            Livewire::test(ViewNovel::class, ['record' => $novel->id])
                ->assertSee('中文检索实验室')->assertSee($url, false);
            Livewire::test(SearchLab::class, ['record' => $novel->id])->assertSee('检索正式正文');
        }
        Http::assertNothingSent();
    }

    /** 正常响应可按小说复用，其他小说不能使用同一输入的私有缓存。 */
    public function test_embedding_cache_is_scoped_and_audited(): void
    {
        config(['ai.providers.openai.key' => 'sk-controlled-test']);
        Http::fake(['api.openai.com/v1/embeddings' => Http::response(['model' => EmbeddingService::MODEL, 'data' => [['index' => 0, 'embedding' => [1.0, ...array_fill(0, 1535, 0.0)]]], 'usage' => ['prompt_tokens' => 3]], 200, ['x-request-id' => 'test-request'])]);
        $novel = Novel::factory()->create();
        $service = app(EmbeddingService::class);
        $first = $service->embed($novel->id, ['检索测试'], 'query');
        $this->assertFalse($first['cached']);
        $this->assertTrue($service->embed($novel->id, ['检索测试'], 'query')['cached']);
        $service->embed(Novel::factory()->create()->id, ['检索测试'], 'query');
        Http::assertSentCount(2);
        $this->assertSame(2, CostLog::count());
        $log = CostLog::findOrFail($first['log_id']);
        $this->assertSame('C05', $log->request_parameters['task']);
        $this->assertSame(3, $log->input_tokens);
        $this->assertNull($log->actual_cost);
        $this->assertSame('unknown', $log->billing_status);
        $this->assertSame('test-request', $log->provider_request_id);
        $this->assertCount(1536, $first['vectors'][0]);
        Livewire::test(SearchLab::class, ['record' => $novel->id])->assertSee('检索正式正文')->set('query', '')->call('search')->assertHasErrors(['query']);
        // 没有正式章节时不得默认为全书或接受手工伪造的范围，也不能发送请求。
        Livewire::test(SearchLab::class, ['record' => $novel->id])->set('query', '测试')->call('search')->assertHasErrors(['throughChapter'])->assertSet('result', null);
        Http::assertSentCount(2);
    }

    /** 503不重试，响应脱敏且未知费用保留。 */
    public function test_error_response_is_preserved_without_secret_or_retry(): void
    {
        config(['ai.providers.openai.key' => 'sk-controlled-test']);
        Http::fake(['api.openai.com/v1/embeddings' => Http::response(['error' => ['message' => 'Bearer sk-controlled-test']], 503)]);
        try {
            app(EmbeddingService::class)->embed(Novel::factory()->create()->id, ['测试'], 'query');
            $this->fail('失败响应不能成功');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('http_503', $exception->getMessage());
        }
        Http::assertSentCount(1);
        $log = CostLog::sole();
        $this->assertSame('unknown', $log->status);
        $this->assertStringNotContainsString('sk-controlled-test', json_encode($log->raw_response));
        $this->assertArrayHasKey('error', $log->raw_response);
        $this->assertNull($log->actual_cost);
    }

    /** 错维度保留原始响应，不能污染索引或成功缓存。 */
    public function test_invalid_vector_retains_evidence_and_never_succeeds(): void
    {
        config(['ai.providers.openai.key' => 'sk-controlled-test']);
        Http::fake(['api.openai.com/v1/embeddings' => Http::response(['model' => EmbeddingService::MODEL, 'data' => [['index' => 0, 'embedding' => [1, 2]]], 'usage' => ['prompt_tokens' => 3]])]);
        try {
            app(EmbeddingService::class)->embed(Novel::factory()->create()->id, ['测试'], 'query');
            $this->fail('错误维度不能成功');
        } catch (RuntimeException) {
            $this->assertSame([1, 2], CostLog::sole()->raw_response['data'][0]['embedding']);
        }
        $this->assertNull(CostLog::sole()->structured_output);
        Http::assertSentCount(1);
    }
}
