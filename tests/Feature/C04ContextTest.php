<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ViewContextManifests;
use App\Models\ContextManifest;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\User;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Pipeline\ContentExecutionService;
use App\Services\Pipeline\ContentGenerationService;
use App\Services\Pipeline\ContextBuilder;
use App\Services\Pipeline\PlanningJson;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** 上下文优先级、容量阻塞、实际请求关联及后台只读验收。 */
class C04ContextTest extends TestCase
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
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 低相关记忆整体裁剪，硬约束与到期伏笔保留且顺序稳定。 */
    public function test_priority_trimming_and_hard_overflow(): void
    {
        $builder = app(ContextBuilder::class);
        $payload = ['candidate_roadmap' => ['ending_direction' => '归还旧信', 'major_turning_points' => [['chapter_number' => 5, 'change' => '查明来源']], 'whole_story_summary' => str_repeat('摘要', 3000)], 'style_examples' => str_repeat('文风', 3000), 'long_term_memory' => str_repeat('旧记忆', 3000), 'confirmed_setting' => ['禁忌' => '不可复活'], 'chapter_plan' => ['goal' => '找回旧信'], 'foreshadow_context' => ['items' => [['id' => 8, 'title' => '旧信', 'must_address' => true]]], 'prior_context' => ['state' => ['alive' => false], 'entities' => ['人甲'], 'chapters' => []]];
        $document = $builder->build($payload, '冻结输入：', '系统任务', ['type' => 'object'], [...$builder->policy(), 'capacity' => 7000], 100, ['input_hash' => 'frozen-v1']);
        $this->assertSame('ready', $document['status']);
        $this->assertArrayNotHasKey('style_examples', $document['payload']);
        $this->assertArrayNotHasKey('long_term_memory', $document['payload']);
        $this->assertSame($payload['foreshadow_context'], $document['payload']['foreshadow_context']);
        $this->assertSame($payload['confirmed_setting'], $document['payload']['confirmed_setting']);
        $priorities = array_column($document['entries'], 'priority');
        $sorted = $priorities;
        sort($sorted);
        $this->assertSame($sorted, $priorities);
        $this->assertSame('归还旧信', $document['payload']['author_future_constraints']['ending_direction']);
        $this->assertCount(3, array_filter($document['entries'], fn ($entry) => ! $entry['selected']));
        $blocked = $builder->build($payload, '冻结输入：', str_repeat('系统硬约束', 4000), [], [...$builder->policy(), 'capacity' => 7000], 100, ['input_hash' => 'frozen-v1']);
        $this->assertSame('blocked', $blocked['status']);
        $this->assertSame($payload['foreshadow_context'], $blocked['payload']['foreshadow_context']);
        $this->assertSame(PlanningJson::hash($document), PlanningJson::hash(json_decode(json_encode($document), true)));
    }

    /** 真正执行正文入口，预算在启动时冻结；阻塞持久化且没有HTTP或成本流水。 */
    public function test_pipeline_blocks_before_provider_and_persists_manifest(): void
    {
        config(['ai.context.capacity' => 100]);
        $novel = B04ContentTest::readyNovel(false, 20);
        $run = app(ContentGenerationService::class)->start($novel, auth()->user());
        config(['ai.context.capacity' => 999999]);
        app(ContentExecutionService::class)->runStep($run->id, $run->invocation_id, app(AiTextGenerationService::class));
        $this->assertSame('context_capacity', $run->fresh()->stop_reason);
        $manifest = ContextManifest::sole();
        $this->assertSame('blocked', $manifest->status);
        $this->assertSame(100, $manifest->document['policy']['capacity']);
        $this->assertDatabaseCount('cost_logs', 0);
        Http::assertNothingSent();
    }

    /** 检查器呈现裁剪原因和来源；旧小说空态不伪造历史清单。 */
    public function test_inspector_is_scoped_readonly_and_shows_manifest_details(): void
    {
        $builder = app(ContextBuilder::class);
        $novel = Novel::factory()->create();
        $document = $builder->build(['confirmed_setting' => ['禁忌' => '不能复活'], 'foreshadow_context' => ['items' => [['title' => '旧信', 'must_address' => true]]], 'style_examples' => str_repeat('旧文风', 3000)], '冻结输入：', '系统', [], [...$builder->policy(), 'capacity' => 6000], 100, ['input_hash' => 'immutable-source']);
        $manifest = ContextManifest::factory()->create(['novel_id' => $novel->id, 'document' => $document, 'document_hash' => PlanningJson::hash($document)]);
        $page = Livewire::withQueryParams(['manifest' => $manifest->id])->test(ViewContextManifests::class, ['record' => $novel->id]);
        $html = $page->html();
        foreach (['不可裁剪', '到期伏笔', '已裁剪', 'immutable-source', '无关联调用'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $other = Novel::factory()->create();
        Livewire::withQueryParams([])->test(ViewContextManifests::class, ['record' => $other->id])->assertCanNotSeeTableRecords([$manifest]);
        Livewire::withQueryParams(['manifest' => $manifest->id])->test(ViewContextManifests::class, ['record' => $other->id])->assertNotFound();
        $this->assertSame(0, CostLog::count());
        auth()->logout();
        $this->get(NovelResource::getUrl('contexts', ['record' => $novel]))->assertRedirect();
    }
}
