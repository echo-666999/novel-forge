<?php

namespace Tests\Feature;

use App\Filament\Pages\CreatePromptTemplate;
use App\Filament\Pages\ManagePromptTemplates;
use App\Jobs\RunSettingJob;
use App\Models\CostLog;
use App\Models\User;
use App\Services\Ai\ChapterGenerationAgent;
use App\Services\Ai\OutlineGenerationAgent;
use App\Services\Ai\PromptTemplateManager;
use App\Services\Ai\SettingDecisionAgent;
use App\Services\Ai\SettingGenerationAgent;
use App\Services\Ai\SubmissionAgent;
use App\Services\Ai\TextRequestOptions;
use App\Services\Pipeline\ContentExecutionContract;
use App\Services\Pipeline\CreateNovelService;
use App\Services\Pipeline\SettingExecutor;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** 经实际队列处理器与官方SDK受控HTTP验证模板发布和旧运行隔离。 */
class C06PromptTemplateTest extends TestCase
{
    use DatabaseMigrations;

    /** 内存库迁移完整应用及设置。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 禁止外部请求，独立账号及假队列避免后台任务泄漏。 */
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        config(['app.env' => 'local', 'ai.providers.openai.key' => 'sk-c06-test-only', 'ai.providers.openai.url' => 'https://api.openai.com/v1']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
        Queue::fake();
    }

    /** 仅在内存库释放不可变历史，绝不清理开发库证据。 */
    protected function tearDown(): void
    {
        $this->assertDatabaseIdentity();
        DB::table('prompt_template_events')->delete();
        DB::table('prompt_template_routes')->update(['version_id' => null]);
        DB::table('prompt_template_versions')->delete();
        B04ContentTest::clearFixtures();
        parent::tearDown();
    }

    /** 清理前严格核验隔离数据库。 */
    protected function assertDatabaseIdentity(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    /** 发布前创建的旧书延迟执行仍使用旧版本，新书使用新版本，回滚不会重写二者。 */
    public function test_real_program_request_uses_frozen_common_and_stage_versions(): void
    {
        $manager = app(PromptTemplateManager::class);
        $common = $this->publish('common', '公共要求：{{ title }}；{{ genre }}；{{ stage }}');
        $first = $this->publish('setting', '旧版要求：保持行动具体。');
        $base = DB::table('prompt_template_routes')->where('scope', 'builtin_setting')->value('version_id');
        $fixed = $manager->draft('builtin_setting', '固定指令旧版', '真实程序路径验收', auth()->id());
        $manager->test($fixed);
        $manager->activate($fixed, $base, auth()->id());
        $old = app(CreateNovelService::class)->createAndStart(B02SettingTest::input(), false)->novel;
        $second = $manager->draft('setting', '新版要求：减少抽象总结。', '新版验收', auth()->id());
        $manager->test($second);
        $manager->activate($second, $first, auth()->id());
        $nextFixed = $manager->draft('builtin_setting', '固定指令新版', '验证新书切换', auth()->id());
        $manager->test($nextFixed);
        $manager->activate($nextFixed, $fixed, auth()->id());
        $new = app(CreateNovelService::class)->createAndStart(B02SettingTest::input(), false)->novel;
        $manager->activate($first, $second, auth()->id(), true);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(B02SettingTest::response(json_encode(B02SettingTest::setting())))]);
        foreach ([$old, $new] as $novel) {
            (new RunSettingJob($novel->id, $novel->generation_config['invocation_id']))->handle(app(SettingExecutor::class));
        }
        Http::assertSentCount(2);
        $requests = Http::recorded()->values();
        $oldPayload = json_encode($requests[0][0]->data(), JSON_UNESCAPED_UNICODE);
        $newPayload = json_encode($requests[1][0]->data(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('固定指令旧版', $oldPayload);
        $this->assertStringNotContainsString('固定指令新版', $oldPayload);
        $this->assertStringContainsString('固定指令新版', $newPayload);
        $this->assertStringNotContainsString('固定指令旧版', $newPayload);
        $this->assertStringContainsString('旧版要求', $oldPayload);
        $this->assertStringNotContainsString('新版要求', $oldPayload);
        $this->assertStringContainsString('新版要求', $newPayload);
        $this->assertStringNotContainsString('旧版要求', $newPayload);
        $this->assertStringContainsString('公共要求：'.$old->title, $oldPayload);
        $this->assertTrue($requests[0][0]['text']['format']['strict']);
        $this->assertSame($requests[0][0]['text']['format']['schema'], $requests[1][0]['text']['format']['schema']);
        $this->assertSame($first, $old->fresh()->generation_config['prompt_templates']['items']['setting']['id']);
        $this->assertSame($second, $new->fresh()->generation_config['prompt_templates']['items']['setting']['id']);
        foreach (CostLog::orderBy('id')->get() as $index => $log) {
            $this->assertSame('succeeded', $log->status);
            $this->assertSame([$first, $second][$index], $log->request_parameters['prompt_templates']['items']['setting']['id']);
            $this->assertSame($common, $log->request_parameters['prompt_templates']['items']['common']['id']);
            $this->assertSame($log->request_parameters['prompt_audit']['system_prompt'], $requests[$index][0]['input'][0]['content']);
        }
        $this->assertSame($first, $manager->snapshot()['items']['setting']['id']);
        $this->assertDatabaseHas('prompt_template_events', ['action' => 'rollback', 'from_version_id' => $second, 'to_version_id' => $first]);
    }

    /** 未测试版本、错误变量、并发旧指针均不能发布；历史版本不可重新测试改写。 */
    public function test_publication_guards_and_snapshot_integrity(): void
    {
        $manager = app(PromptTemplateManager::class);
        $id = $manager->draft('draft', '正文要求', '待测试', auth()->id());
        $this->reject(fn () => $manager->activate($id, null, auth()->id()));
        $bad = $manager->draft('draft', '{{ unknown }}', '错误变量', auth()->id());
        $this->reject(fn () => $manager->test($bad));
        $this->assertDatabaseHas('prompt_template_versions', ['id' => $bad, 'status' => 'draft']);
        $manager->test($id);
        $manager->activate($id, null, auth()->id());
        $this->reject(fn () => $manager->test($id));
        $next = $manager->draft('draft', '新正文要求', '并发发布', auth()->id());
        $manager->test($next);
        $this->reject(fn () => $manager->activate($next, null, auth()->id()));
        $snapshot = $manager->snapshot();
        $snapshot['items']['draft']['body'] = '篡改';
        $this->reject(fn () => $manager->instructions($snapshot, 'draft', []));
        $this->assertSame('', $manager->instructions(null, 'draft', []));
        $manager->retire('draft', $id, auth()->id());
        $this->assertArrayNotHasKey('draft', $manager->snapshot()['items']);
        Http::assertNothingSent();
    }

    /** 管理页面完整覆盖保存、测试、发布、复制比较、退役与回滚动作。 */
    public function test_admin_workflow_and_readonly_history(): void
    {
        $page = Livewire::test(ManagePromptTemplates::class)->assertSee('输出 Schema')->fillForm(['scope' => 'outline', 'body' => '大纲 {{ title }}', 'notes' => '首版'])->call('saveDraft')->assertHasNoErrors();
        $first = DB::table('prompt_template_versions')->where('scope', 'outline')->sole()->id;
        $page->call('publishVersion', $first)->assertHasErrors('template');
        $page->call('testVersion', $first)->assertHasNoErrors()->call('publishVersion', $first)->assertHasNoErrors()->assertSee('已发布')->call('copyVersion', $first)->assertSet('body', '大纲 {{ title }}')->set('body', '新版大纲')->set('notes', '二版')->call('saveDraft');
        $second = DB::table('prompt_template_versions')->max('id');
        $page->call('testVersion', $second)->call('publishVersion', $second)->assertHasNoErrors()->call('publishVersion', $first, true)->assertHasNoErrors()->assertNotified('已回滚')->call('retireVersion', 'outline')->assertHasNoErrors();
        $this->assertDatabaseHas('prompt_template_versions', ['id' => $first, 'body' => '大纲 {{ title }}']);
        $this->assertSame(4, DB::table('prompt_template_events')->where('scope', 'outline')->count());
        Http::assertNothingSent();
    }

    /** 各阶段采用各自模板，公共模板始终参与，旧选项的程序契约保持逐字不变。 */
    public function test_all_stage_agents_resolve_only_their_frozen_template(): void
    {
        $manager = app(PromptTemplateManager::class);
        $this->publish('common', '公共标记');
        foreach (array_diff(array_keys(PromptTemplateManager::SCOPES), ['common']) as $scope) {
            $this->publish($scope, '阶段标记-'.$scope);
        }
        $snapshot = $manager->snapshot();
        $cases = [
            ['setting', TextRequestOptions::setting(0.7), SettingGenerationAgent::class],
            ['decision', TextRequestOptions::decision(), SettingDecisionAgent::class],
            ['outline', TextRequestOptions::outline(0.7, 1, 3, 3), OutlineGenerationAgent::class],
        ];
        foreach (['fine', 'draft', 'revise', 'check'] as $scope) {
            $cases[] = [$scope, TextRequestOptions::executionChapter($scope, 0.7, ['anchors' => [], 'rules' => [], 'findings' => [], 'budget' => ['slots' => ['p1'], 'min' => 344, 'max' => 406]]), ChapterGenerationAgent::class];
        }
        foreach (['review', 'extract'] as $scope) {
            $cases[] = [$scope, TextRequestOptions::submission($scope, ['anchors' => ['p1' => '测试正文']]), SubmissionAgent::class];
        }
        foreach ($cases as [$scope, $options, $class]) {
            $original = (new $class($options))->instructions();
            $frozen = $options->withTemplates($snapshot, ['title' => '书名', 'genre' => '现实']);
            // 模板仍冻结，动态输出契约在其后附加，防止补充文本覆盖字数与JSON格式。
            $contract = match ($class) {
                ChapterGenerationAgent::class => ContentExecutionContract::outputInstructions($scope, $options->executionContext),
                SubmissionAgent::class => SubmissionAgent::outputInstructions($scope),
                default => '',
            };
            $base = $contract === '' ? $original : substr($original, 0, -strlen($contract));
            $this->assertSame($base.$frozen->templateInstructions.$contract, (new $class($frozen))->instructions());
            $this->assertStringContainsString('公共标记', $frozen->templateInstructions);
            $this->assertStringContainsString('阶段标记-'.$scope, $frozen->templateInstructions);
            $this->assertSame(1, substr_count($frozen->templateInstructions, '阶段标记-'));
            $this->assertSame('', $options->templateInstructions);
        }
        Http::assertNothingSent();
    }

    /** 固定文本发布替换真正的系统提示词，旧小说快照和无快照历史均不被改变。 */
    public function test_builtin_publication_replaces_only_new_run_fixed_instructions(): void
    {
        $manager = app(PromptTemplateManager::class);
        $old = $manager->snapshot();
        $original = (new SettingGenerationAgent(TextRequestOptions::setting(0.7)))->instructions();
        $id = $manager->draft('builtin_setting', '新版固定设定指令。', '后台维护固定文本', auth()->id());
        $manager->test($id);
        $manager->activate($id, $old['items']['builtin_setting']['id'], auth()->id());
        $variables = ['title' => '测试书', 'genre' => '现实'];
        $this->assertSame($original, (new SettingGenerationAgent(TextRequestOptions::setting(0.7)->withTemplates($old, $variables)))->instructions());
        $this->assertSame('新版固定设定指令。', (new SettingGenerationAgent(TextRequestOptions::setting(0.7)->withTemplates($manager->snapshot(), $variables)))->instructions());
        $this->assertSame($original, (new SettingGenerationAgent(TextRequestOptions::setting(0.7)))->instructions());
        $detail = Livewire::test(ManagePromptTemplates::class)->assertSee('内置固定')->assertDontSee('新版固定设定指令。')->mountAction('details', ['id' => $id])->assertActionMounted('details');
        $this->assertSame('新版固定设定指令。', collect($detail->instance()->getSchema('mountedActionSchema0')->getComponents())->first(fn ($entry): bool => $entry instanceof TextEntry && $entry->getName() === 'body')->getState());
        Http::assertNothingSent();
    }

    /** 类型筛选隔离固定文本与补充要求，复制编辑同步显示所选类型。 */
    public function test_admin_distinguishes_template_types(): void
    {
        $id = app(PromptTemplateManager::class)->draft('common', '仅用于类型筛选的补充文本', '类型筛选验收', auth()->id());
        $page = Livewire::test(ManagePromptTemplates::class)
            ->assertSee('全部类型')->assertSee('新增')
            ->set('filterType', 'builtin')->assertDontSee('类型筛选验收')
            ->set('filterType', 'supplement')->assertSee('类型筛选验收')
            ->assertDontSee('生效方式：替换该阶段固定文本。');
        $editor = Livewire::test(CreatePromptTemplate::class)->call('copyVersion', $id)->assertSee('当前类型：补充创作要求。');
        $fixed = DB::table('prompt_template_routes')->where('scope', 'builtin_setting')->value('version_id');
        $editor->call('copyVersion', $fixed)->assertSet('scope', 'builtin_setting')->assertSee('当前类型：内置固定提示词。');
        Http::assertNothingSent();
    }

    /** 不同类型和阶段独立编号，筛选、退役和回滚均不得把全局编号当版本号。 */
    public function test_versions_are_numbered_per_scope_before_filtering_and_grouped_by_stage(): void
    {
        $manager = app(PromptTemplateManager::class);
        $first = $manager->draft('setting', '补充设定第一版', '首版', auth()->id());
        $manager->test($first);
        $manager->activate($first, null, auth()->id());
        $manager->draft('outline', '补充大纲第一版', '首版', auth()->id());
        $second = $manager->draft('setting', '补充设定第二版', '次版', auth()->id());
        $manager->test($second);
        $manager->activate($second, $first, auth()->id());
        $page = Livewire::test(ManagePromptTemplates::class)->assertSee('各阶段当前生效')->assertSee('创建时间');
        $this->assertSame(10, $page->instance()->getTableRecords()->perPage());
        // 已退役的设定 V1 默认收起，不计入列表。
        $this->assertSame(11, $page->instance()->getTableRecords()->total());
        $page->assertSee('已收起 1 个已退役旧版本');
        $page->set('filterScope', 'setting')->set('filterStatus', 'published');
        $this->assertSame([$second], $page->instance()->getTableRecords()->getCollection()->pluck('id')->all());
        $this->assertSame(['V2'], $page->instance()->getTableRecords()->getCollection()->pluck('version')->all());
        $page->call('copyVersion', $second)->assertSet('notes', '基于V2（记录 #'.$second.'）：');
        $page->call('publishVersion', $first, true)->assertHasNoErrors();
        $this->assertSame(['V1'], $page->instance()->getTableRecords()->getCollection()->pluck('version')->all());
        $page->set('filterStatus', '');
        $this->assertSame([$second, $first], $page->instance()->getTableRecords()->getCollection()->pluck('id')->all());
        // 筛选前完整历史编号，翻页后筛选必须回到第一页。
        $page->set('filterScope', '')->call('setPage', 2);
        $this->assertSame(2, $page->instance()->getTableRecords()->currentPage());
        $page->set('filterType', 'supplement');
        $this->assertSame(1, $page->instance()->getTableRecords()->currentPage());
        $this->assertSame(3, $page->instance()->getTableRecords()->total());
        $page->mountTableAction('view', (string) $second);
        $this->assertSame('view', $page->instance()->mountedActions[0]['name']);
        // 非发布版本按段落与当前发布版本对照，新版本正文出现在对照中。
        $modal = $page->instance()->getSchema('mountedActionSchema0')->toHtml();
        $this->assertStringContainsString('补充设定第<mark class="nf-body-mark">二</mark>版', $modal);
        $this->assertStringContainsString('本版本 V2', $modal);
        Http::assertNothingSent();
    }

    /** 列表不展开正文，新建独立表单保存草稿后返回列表且不自动发布。 */
    public function test_separate_create_page_and_detail_modal(): void
    {
        $page = Livewire::test(CreatePromptTemplate::class)->assertSee('新增模板草稿')
            ->call('saveDraft')->assertHasErrors(['body', 'notes'])
            ->fillForm(['scope' => 'draft', 'body' => '弹窗专用完整正文', 'notes' => '独立新增验收'])
            ->call('saveDraft')->assertHasNoErrors()->assertRedirect(ManagePromptTemplates::getUrl());
        $record = DB::table('prompt_template_versions')->where('scope', 'draft')->sole();
        $this->assertSame('draft', $record->status);
        $this->assertNull(DB::table('prompt_template_routes')->where('scope', 'draft')->value('version_id'));
        $detail = Livewire::test(ManagePromptTemplates::class)->assertDontSee('弹窗专用完整正文')->assertDontSee('保存为新草稿')
            ->assertSee(CreatePromptTemplate::getUrl(), false)
            ->mountAction('details', ['id' => $record->id])->assertActionMounted('details');
        $this->assertSame('弹窗专用完整正文', collect($detail->instance()->getSchema('mountedActionSchema0')->getComponents())->first(fn ($entry): bool => $entry instanceof TextEntry && $entry->getName() === 'body')->getState());
        Livewire::withQueryParams(['copy_from' => $record->id])->test(CreatePromptTemplate::class)
            ->assertSet('body', '弹窗专用完整正文')->assertSet('scope', 'draft');
        Http::assertNothingSent();
    }

    /** 顶部概览显示各阶段生效版本；草稿一步“测试并发布”，列表不再显示冻结快照全文和记录编号列。 */
    public function test_overview_and_one_step_test_and_publish(): void
    {
        $id = app(PromptTemplateManager::class)->draft('outline', '一步发布的大纲补充', '一步发布', auth()->id());
        $page = Livewire::test(ManagePromptTemplates::class)->assertSee('各阶段当前生效')->assertSee('无补充')
            ->assertDontSee('小说根运行冻结记录')->assertDontSee('记录编号')
            ->callTableAction('testAndPublish', (string) $id)->assertHasNoTableActionErrors()->assertNotified('已发布');
        $this->assertSame('published', DB::table('prompt_template_versions')->where('id', $id)->value('status'));
        $page->assertSee('补充 V1');
        Http::assertNothingSent();
    }

    /** 详情弹窗：标题行带状态，生效、回归与使用情况为一排标签，正文放在无缩进空白的框内。 */
    public function test_detail_modal_is_compact(): void
    {
        $fixed = DB::table('prompt_template_routes')->where('scope', 'builtin_decision')->value('version_id');
        $page = Livewire::test(ManagePromptTemplates::class)->mountAction('details', ['id' => $fixed]);
        $modal = $page->instance()->getSchema('mountedActionSchema0')->toHtml();
        $this->assertStringContainsString('当前生效版本', $modal);
        $this->assertStringContainsString('暂无小说使用', $modal);
        $this->assertStringContainsString('<div style="white-space:pre-wrap;', $modal);
        $this->assertStringNotContainsString('完整模板正文', $modal);
        Http::assertNothingSent();
    }

    /** 详情弹窗底部按状态直接操作；已退役旧版本默认收起并提示数量。 */
    public function test_detail_modal_actions_and_retired_versions_collapsed(): void
    {
        $manager = app(PromptTemplateManager::class);
        $first = $manager->draft('outline', '大纲补充第一版', '首版', auth()->id());
        $page = Livewire::test(ManagePromptTemplates::class)
            ->assertActionVisible([['name' => 'details', 'arguments' => ['id' => $first]], 'detailTestAndPublish'])
            ->assertActionHidden([['name' => 'details', 'arguments' => ['id' => $first]], 'detailRollback'])
            ->callAction([['name' => 'details', 'arguments' => ['id' => $first]], 'detailTestAndPublish'])->assertNotified('已发布');
        $this->assertSame('published', DB::table('prompt_template_versions')->where('id', $first)->value('status'));

        $second = $manager->draft('outline', '大纲补充第二版', '次版', auth()->id());
        $manager->test($second);
        $page = Livewire::test(ManagePromptTemplates::class)
            ->callAction([['name' => 'details', 'arguments' => ['id' => $second]], 'detailPublish'])->assertNotified('已发布');
        $this->assertSame('retired', DB::table('prompt_template_versions')->where('id', $first)->value('status'));

        // 默认不列已退役版本，提示收起数量；切到全部状态后出现，并可在弹窗内回滚。
        $page->assertSee('已收起 1 个已退役旧版本');
        $this->assertNotContains($first, $page->instance()->getTableRecords()->getCollection()->pluck('id')->all());
        $page->set('filterScope', 'outline')->set('filterStatus', '')->assertDontSee('已收起');
        $this->assertContains($first, $page->instance()->getTableRecords()->getCollection()->pluck('id')->all());
        $page->callAction([['name' => 'details', 'arguments' => ['id' => $first]], 'detailRollback'])->assertNotified('已回滚');
        $this->assertSame('published', DB::table('prompt_template_versions')->where('id', $first)->value('status'));
        Http::assertNothingSent();
    }

    /** 拼接预览按运行时顺序列出固定文本、公共补充与本阶段补充，编辑中的一段替换为当前输入。 */
    public function test_editor_shows_assembled_template_parts(): void
    {
        $this->publish('common', '公共要求：{{ stage }} 阶段保持克制');
        $editor = Livewire::withQueryParams(['scope' => 'draft'])->test(CreatePromptTemplate::class)->set('body', '正文补充 {{ title }}');
        $assembly = $editor->instance()->assembly();
        $this->assertSame('正文', $assembly['stage']);
        $this->assertSame(['固定文本', '公共补充', '正文补充'], array_column($assembly['parts'], 'source'));
        $this->assertSame(['公共要求：draft 阶段保持克制', '正文补充 验收样例书名'], array_column(array_slice($assembly['parts'], 1), 'body'));
        $this->assertSame([false, false, true], array_column($assembly['parts'], 'editing'));
        $editor->assertSee('拼接后的模板部分 · 正文')->assertSee(PromptTemplateManager::SUPPLEMENT_HEADING)->assertSee('本书上下文');

        // 编辑公共补充时按示例阶段拼接，阶段变量随示例阶段替换。
        $common = Livewire::withQueryParams(['scope' => 'common'])->test(CreatePromptTemplate::class)->set('assemblyStage', 'outline')->assertSee('示例阶段');
        $assembly = $common->instance()->assembly();
        $this->assertSame('大纲', $assembly['stage']);
        $this->assertSame(['固定文本', '公共补充'], array_column($assembly['parts'], 'source'));
        $this->assertSame('公共要求：outline 阶段保持克制', $assembly['parts'][1]['body']);
        $this->assertTrue($assembly['parts'][1]['editing']);
        Http::assertNothingSent();
    }

    /** 编辑页按阶段带出生效正文，作者改过的正文不被切换覆盖；预览渲染样例变量并对照生效版本；一步保存并发布。 */
    public function test_editor_loads_published_previews_and_saves_and_publishes(): void
    {
        $fixedBody = DB::table('prompt_template_versions')->where('id', DB::table('prompt_template_routes')->where('scope', 'builtin_draft')->value('version_id'))->value('body');
        $editor = Livewire::withQueryParams(['scope' => 'builtin_draft'])->test(CreatePromptTemplate::class)
            ->assertSet('scope', 'builtin_draft')->assertSet('body', $fixedBody)->assertSee('与当前生效的')->assertSee('完全相同');
        $editor->set('scope', 'draft')->assertSet('body', '')->set('body', '书名 {{ title }} 的正文要求')
            ->assertSee('书名 验收样例书名 的正文要求')->assertSee('本阶段暂无生效版本')
            ->set('scope', 'outline')->assertSet('body', '书名 {{ title }} 的正文要求');
        $editor->set('body', '坏变量 {{ unknown }}')->assertSee('未知模板变量：unknown');

        Livewire::test(CreatePromptTemplate::class)->set('scope', 'draft')->set('body', '一步发布 {{ genre }}')->set('notes', '一步发布')
            ->call('saveAndPublish')->assertHasNoErrors()->assertNotified()->assertRedirect(ManagePromptTemplates::getUrl());
        $id = DB::table('prompt_template_routes')->where('scope', 'draft')->value('version_id');
        $this->assertSame('一步发布 {{ genre }}', DB::table('prompt_template_versions')->where('id', $id)->value('body'));
        $this->assertSame('published', DB::table('prompt_template_versions')->where('id', $id)->value('status'));

        // 概览卡片：已生效项可点开详情，未生效补充提供“添加”入口并带上阶段参数。
        $page = Livewire::test(ManagePromptTemplates::class)->assertSee('补充 V1')->assertSee('添加')
            ->assertSee(CreatePromptTemplate::getUrl(['scope' => 'builtin_draft']), false)
            ->assertSeeHtml("mountAction('details', { id: ".$id.' })');
        $page->mountAction('details', ['id' => $id])->assertActionMounted('details');
        Http::assertNothingSent();
    }

    /** 构造已测试并发布的模板，不产生模型请求。 */
    private function publish(string $scope, string $body): int
    {
        $manager = app(PromptTemplateManager::class);
        $id = $manager->draft($scope, $body, '隔离验收版本', auth()->id());
        $manager->test($id);
        $manager->activate($id, null, auth()->id());

        return $id;
    }

    /** 预期失败后继续验证同一事务边界内没有部分发布。 */
    private function reject(callable $action): void
    {
        try {
            $action();
            $this->fail('应当阻止非法模板操作');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}
