<?php

namespace Tests\Feature;

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
use App\Services\Pipeline\CreateNovelService;
use App\Services\Pipeline\SettingExecutor;
use Filament\Facades\Filament;
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
        $old = app(CreateNovelService::class)->createAndStart(B02SettingTest::input(), false)->novel;
        $second = $manager->draft('setting', '新版要求：减少抽象总结。', '新版验收', auth()->id());
        $manager->test($second);
        $manager->activate($second, $first, auth()->id());
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
        $this->assertStringContainsString('旧版要求', $oldPayload);
        $this->assertStringNotContainsString('新版要求', $oldPayload);
        $this->assertStringContainsString('新版要求', $newPayload);
        $this->assertStringNotContainsString('旧版要求', $newPayload);
        $this->assertStringContainsString('公共要求：'.$old->title, $oldPayload);
        $this->assertStringContainsString('JSON Schema', $oldPayload);
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
        $this->assertSame([], $manager->snapshot()['items']);
        Http::assertNothingSent();
    }

    /** 管理页面完整覆盖保存、测试、发布、复制比较、退役与回滚动作。 */
    public function test_admin_workflow_and_readonly_history(): void
    {
        $page = Livewire::test(ManagePromptTemplates::class)->assertSee('输出 Schema')->fillForm(['scope' => 'outline', 'body' => '大纲 {{ title }}', 'notes' => '首版'])->call('saveDraft')->assertHasNoErrors();
        $first = DB::table('prompt_template_versions')->sole()->id;
        $page->call('publishVersion', $first)->assertHasErrors('template');
        $page->call('testVersion', $first)->assertHasNoErrors()->call('publishVersion', $first)->assertHasNoErrors()->assertSee('已发布')->call('copyVersion', $first)->assertSet('body', '大纲 {{ title }}')->set('body', '新版大纲')->set('notes', '二版')->call('saveDraft');
        $second = DB::table('prompt_template_versions')->max('id');
        $page->call('testVersion', $second)->call('publishVersion', $second)->assertHasNoErrors()->call('publishVersion', $first, true)->assertHasNoErrors()->assertSee('已回滚')->call('retireVersion', 'outline')->assertHasNoErrors();
        $this->assertDatabaseHas('prompt_template_versions', ['id' => $first, 'body' => '大纲 {{ title }}']);
        $this->assertDatabaseCount('prompt_template_events', 4);
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
            $cases[] = [$scope, TextRequestOptions::executionChapter($scope, 0.7, ['anchors' => [], 'rules' => [], 'findings' => []]), ChapterGenerationAgent::class];
        }
        foreach (['review', 'extract'] as $scope) {
            $cases[] = [$scope, TextRequestOptions::submission($scope, ['anchors' => ['p1' => '测试正文']]), SubmissionAgent::class];
        }
        foreach ($cases as [$scope, $options, $class]) {
            $original = (new $class($options))->instructions();
            $frozen = $options->withTemplates($snapshot, ['title' => '书名', 'genre' => '现实']);
            $this->assertSame($original.$frozen->templateInstructions, (new $class($frozen))->instructions());
            $this->assertStringContainsString('公共标记', $frozen->templateInstructions);
            $this->assertStringContainsString('阶段标记-'.$scope, $frozen->templateInstructions);
            $this->assertSame(1, substr_count($frozen->templateInstructions, '阶段标记-'));
            $this->assertSame('', $options->templateInstructions);
        }
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
