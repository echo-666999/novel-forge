<?php

namespace Tests\Feature;

use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ViewContextManifests;
use App\Models\ContextManifest;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\User;
use App\Services\Ai\AiTextGenerationService;
use App\Services\Ai\SharedContextLayout;
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
        $this->assertSame($payload['foreshadow_context'], $document['shared_payload']['foreshadow_context']);
        $this->assertSame($payload['confirmed_setting'], $document['shared_payload']['confirmed_setting']);
        $priorities = array_column($document['entries'], 'priority');
        $sorted = $priorities;
        sort($sorted);
        $this->assertSame($sorted, $priorities);
        $this->assertSame('归还旧信', $document['payload']['author_future_constraints']['ending_direction']);
        $this->assertCount(3, array_filter($document['entries'], fn ($entry) => ! $entry['selected']));
        $blocked = $builder->build($payload, '冻结输入：', str_repeat('系统硬约束', 4000), [], [...$builder->policy(), 'capacity' => 7000], 100, ['input_hash' => 'frozen-v1']);
        $this->assertSame('blocked', $blocked['status']);
        $this->assertSame($payload['foreshadow_context'], $blocked['shared_payload']['foreshadow_context']);
        $this->assertSame(PlanningJson::hash($document), PlanningJson::hash(json_decode(json_encode($document), true)));
    }

    /** 重复引文可完整还原，不能借去重删除独有证据或扩大冻结容量。 */
    public function test_repeated_quotes_are_lossless_and_unique_evidence_still_blocks(): void
    {
        $builder = app(ContextBuilder::class);
        $quote = str_repeat('原文证据不能省略。', 100);
        $events = [];
        for ($i = 0; $i < 20; $i++) {
            $events[] = ['event_id' => $i, 'fact_category' => $i % 2 ? 'rumor' : 'objective_confirmed', 'evidence' => ['quote' => $quote, 'start' => intdiv($i, 2), 'source' => str_repeat('不可丢失的来源', 20)]];
        }
        $payload = ['events' => $events];
        $policy = [...$builder->policy(), 'capacity' => 15000];
        $document = $builder->build($payload, '', '', [], $policy, 100, []);
        $this->assertSame('ready', $document['status']);
        $encoded = $document['payload'];
        $catalog = $encoded['context_value_catalog'];
        unset($encoded['context_value_catalog'], $encoded['context_value_encoding']);
        // 引用还原必须得到完全一致的事件、类别、坐标及完整引文。
        $decode = function (mixed $value) use (&$decode, $catalog): mixed {
            if (is_array($value) && array_keys($value) === ['context_value_ref']) {
                return $decode($catalog[$value['context_value_ref']]);
            }
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $value[$key] = $decode($item);
                }
            }

            return $value;
        };
        $this->assertSame(PlanningJson::hash($payload), PlanningJson::hash($decode($encoded)));
        $this->assertSame(15000, $document['policy']['capacity']);
        $unique = $builder->build(['events' => [['quote' => str_repeat('唯一证据', 4000)]]], '', '', [], $policy, 100, []);
        $this->assertSame('blocked', $unique['status']);
    }

    /** 新协议把同章各步骤共用的资料放在最前且逐字节一致；发给模型的前文去掉编号与坐标，保留事件、类别与原文引用。 */
    public function test_shared_material_leads_and_is_identical_across_steps(): void
    {
        $builder = app(ContextBuilder::class);
        $prior = ['version' => 'c01-canonical-context-v2', 'state' => ['entities' => []], 'entities' => ['entity-1' => ['name' => '沈禾']], 'previous_ending' => '她合上箱子。',
            'evidence_window' => ['full_evidence_from_chapter' => 2, 'note' => '旧说明'],
            'chapters' => [
                ['number' => 1, 'title' => '锉口', 'commit_id' => 7, 'version_id' => 9, 'body_hash' => str_repeat('a', 64), 'events' => [['event_id' => 31, 'type' => 'observation', 'fact_category' => 'objective_confirmed', 'summary' => '发现锉痕', 'evidence' => [['paragraph' => 1, 'start' => 0, 'end' => 12]]]]],
                ['number' => 2, 'title' => '回执', 'commit_id' => 8, 'version_id' => 10, 'body_hash' => str_repeat('b', 64), 'events' => [['event_id' => 32, 'type' => 'statement', 'fact_category' => 'rumor', 'summary' => '母亲转述', 'evidence' => [['paragraph' => 3, 'start' => 40, 'end' => 52, 'quote' => '“锉口有规矩。”']]]]],
            ]];
        $common = ['prior_context' => $prior, 'confirmed_setting' => ['禁忌' => '不可复活'], 'author_decisions' => [['answer' => '保留旧信']], 'rule_catalog' => ['world-1' => '只能凭档案推进'], 'word_policy' => ['min' => 2000], 'foreshadow_context' => ['items' => []]];
        $review = $builder->build([...$common, 'body_hash' => 'h1', 'evidence_catalog' => ['p1' => '甲']], '冻结提交输入：', '评审指令', ['type' => 'object', 'enum' => ['p1']], $builder->policy(), 100, []);
        $draft = $builder->build([...$common, 'chapter_plan' => ['goal' => '找回旧信'], 'author_idea' => '原始创意'], '本次步骤 draft；冻结输入：', '正文指令', ['type' => 'object'], $builder->policy(), 100, []);

        $this->assertSame('c04-context-v2', $review['version']);
        $this->assertSame('shared-first', $review['layout']);
        [$reviewShared, $reviewRequest] = ContextBuilder::splitShared($review['user_prompt']);
        [$draftShared] = ContextBuilder::splitShared($draft['user_prompt']);
        $this->assertSame($reviewShared, $draftShared);
        $this->assertSame(['author_decisions', 'confirmed_setting', 'rule_catalog', 'word_policy', 'prior_context', 'canonical_entities', 'canonical_state', 'foreshadow_context'], array_keys($review['shared_payload']));
        $this->assertStringStartsWith('冻结提交输入：', $reviewRequest);
        $this->assertSame(['body_hash' => 'h1', 'evidence_catalog' => ['p1' => '甲']], $review['payload']);
        $this->assertSame(['body_hash' => 'h1', 'evidence_catalog' => ['p1' => '甲']], json_decode(explode('冻结提交输入：', $reviewRequest, 2)[1], true));

        $chapters = $review['shared_payload']['prior_context']['chapters'];
        $this->assertSame(['number' => 1, 'title' => '锉口', 'events' => [['type' => 'observation', 'fact_category' => 'objective_confirmed', 'summary' => '发现锉痕']]], $chapters[0]);
        $this->assertSame(['“锉口有规矩。”'], $chapters[1]['events'][0]['quotes']);
        $this->assertArrayNotHasKey('evidence', $chapters[1]['events'][0]);
        $this->assertStringNotContainsString('段落位置', $review['user_prompt']);
        $this->assertSame(['name' => '沈禾'], $review['shared_payload']['canonical_entities']['entity-1']);

        // 清单顺序与实际发送一致：共享资料、系统任务、输出契约，再到本次输入。
        $order = array_column(array_filter($review['entries'], fn (array $entry): bool => $entry['final_order'] !== null), 'final_order', 'id');
        asort($order);
        $this->assertSame(['author_decisions', 'confirmed_setting', 'rule_catalog', 'word_policy', 'prior_context', 'canonical_entities', 'canonical_state', 'foreshadow_context', 'system', 'response_schema', 'body_hash', 'evidence_catalog'], array_keys($order));
        $this->assertSame('shared', collect($review['entries'])->firstWhere('id', 'prior_context')['tier']);
    }

    /** 已冻结旧协议的运行保持原排列与原前文字段，单条用户输入不含共享资料。 */
    public function test_legacy_policy_keeps_original_layout(): void
    {
        $builder = app(ContextBuilder::class);
        $policy = [...$builder->policy(), 'version' => ContextBuilder::LEGACY_VERSION];
        $prior = ['chapters' => [['number' => 1, 'commit_id' => 7, 'events' => [['event_id' => 31, 'evidence' => [['paragraph' => 1, 'start' => 0, 'end' => 3]]]]]]];
        $document = $builder->build(['prior_context' => $prior, 'confirmed_setting' => ['禁忌' => '不可复活'], 'body_hash' => 'h1'], '冻结输入：', '系统', [], $policy, 100, []);

        $this->assertSame('c04-context-v1', $document['version']);
        $this->assertNull(ContextBuilder::splitShared($document['user_prompt']));
        $this->assertArrayNotHasKey('shared_payload', $document);
        $this->assertSame('冻结输入：{"body_hash":"h1","confirmed_setting":{"禁忌":"不可复活"},"prior_context":'.json_encode($prior, JSON_UNESCAPED_UNICODE).'}', $document['user_prompt']);
        $this->assertSame(SharedContextLayout::anthropicMessages([['role' => 'user', 'content' => [['type' => 'text', 'text' => $document['user_prompt']]]]]), [['role' => 'user', 'content' => [['type' => 'text', 'text' => $document['user_prompt']]]]]);
        $this->assertNull(SharedContextLayout::responsesInput([['role' => 'system', 'content' => '系统'], ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $document['user_prompt']]]]]));
    }

    /** Claude 的系统指令固定在顶层，共享资料与本次输入拆成两个文本块，内容不变。 */
    public function test_anthropic_messages_split_shared_material_into_blocks(): void
    {
        $prompt = ContextBuilder::SHARED_MARKER.'{"a":1}'.ContextBuilder::SHARED_SEPARATOR.'冻结输入：{"b":2}';
        $messages = SharedContextLayout::anthropicMessages([['role' => 'user', 'content' => [['type' => 'text', 'text' => $prompt]]]]);

        $this->assertSame([['type' => 'text', 'text' => ContextBuilder::SHARED_MARKER.'{"a":1}'], ['type' => 'text', 'text' => '冻结输入：{"b":2}']], $messages[0]['content']);
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
