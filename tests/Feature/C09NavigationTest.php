<?php

namespace Tests\Feature;

use App\Filament\Pages\GenerationRuns;
use App\Filament\Pages\ManageModelPrices;
use App\Filament\Pages\ProductionOverview;
use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Pages\ViewNovel;
use App\Models\ChapterGenerationRun;
use App\Models\CostLog;
use App\Models\Novel;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** 核心导航的登录保护、空态及不存在资源响应，不发送任何模型请求。 */
class C09NavigationTest extends TestCase
{
    use DatabaseMigrations;

    /** 仅在隔离测试库迁移业务与设置。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 页面读取禁止意外外发。 */
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
    }

    /** 每个现有小说页面在空小说中仍有可读页面，不存在的小说返回404。 */
    #[DataProvider('pages')]
    public function test_novel_pages_have_authentication_empty_and_not_found_states(string $page): void
    {
        $novel = Novel::factory()->create();
        $url = NovelResource::getUrl($page, ['record' => $novel->id]);
        $this->get($url)->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create());
        $this->get($url)->assertOk()->assertSee($novel->title);
        $this->get(NovelResource::getUrl($page, ['record' => 999999]))->assertNotFound();
        Http::assertNothingSent();
    }

    /** 实际已注册的只读和工作台页面，不伪造尚未实现的路由。 */
    public static function pages(): array
    {
        return array_map(fn (string $page): array => [$page], ['entities', 'scenes', 'chapters', 'view', 'run', 'content', 'outline', 'costs', 'state', 'contexts', 'foreshadows', 'search', 'reader', 'exports', 'acceptance', 'knowledge', 'editor', 'requirements', 'blueprints', 'structure']);
    }

    /** 总览列出全部需求编号与真实开放边界，登录账号在非local环境同样可访问。 */
    public function test_overview_and_panel_boundary(): void
    {
        $user = User::factory()->create();
        $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse((new User)->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($user->canAccessPanel(Panel::make()->id('other')));
        $this->actingAs($user);
        $this->get(ProductionOverview::getUrl())->assertOk()->assertSee('待我处理')->assertSee('正在运行')->assertSee('尚无有效消费者观测');
        // UI01～UI32 覆盖清单已从系统健康页移除，后台不再展示开发验收清单。
        $this->get(SystemHealth::getUrl())->assertOk()->assertDontSee('UI01')->assertDontSee('UI32');
        $novel = Novel::factory()->create();
        $this->get(ProductionOverview::getUrl())->assertOk()->assertSee('打开工作台')->assertSee(NovelResource::getUrl('chapters', ['record' => $novel]), false);
    }

    /** 按原始C09范围核对32项导航映射，合用页面也必须指向可访问的实际入口。 */
    public function test_all_32_capability_entries_resolve_to_accessible_pages(): void
    {
        $this->actingAs(User::factory()->create());
        Novel::factory()->create();
        $rows = app(SystemHealth::class)->capabilities();
        $this->assertSame(array_map(fn (int $number): string => sprintf('UI%02d', $number), range(1, 32)), array_column($rows, 'id'));
        foreach ($rows as $row) {
            $this->assertNotEmpty($row['url'], $row['id'].'缺少实际入口');
            $this->get($row['url'])->assertOk();
        }
        Http::assertNothingSent();
    }

    /** 公开注册保持关闭，配置页面须登录，读取页面不触发模型。 */
    public function test_global_pages_are_private_and_registration_is_closed(): void
    {
        $paths = ['/admin', '/admin/generation-runs', '/admin/system-health', '/admin/manage-general-settings', '/admin/manage-prompt-templates', '/admin/novels/create'];
        foreach ($paths as $path) {
            $this->get($path)->assertRedirect('/admin/login');
        }
        $this->get('/admin/register')->assertNotFound();
        $this->actingAs(User::factory()->create());
        foreach ($paths as $path) {
            $this->get($path)->assertOk();
        }
        Http::assertNothingSent();
    }

    /** 生产总览把服务状态压成标签：异常列出说明，金额未知的请求改为标签提醒而不是待办项。 */
    public function test_overview_status_badges_and_unknown_requests(): void
    {
        $this->actingAs(User::factory()->create());
        $novel = Novel::factory()->create();
        CostLog::factory()->create(['novel_id' => $novel->id, 'mode' => 'real', 'status' => 'unknown', 'billing_status' => 'unknown']);

        $this->get(ProductionOverview::getUrl())->assertOk()->assertSee('1 本小说')->assertSee('项服务需排查')
            ->assertSee('Worker 心跳：尚无有效消费者观测')->assertSee('1 次真实请求结果未知')->assertDontSee('真实请求结果未知或尚未返回');
    }

    /** 章节运行中心不再有空说明区块，书名与章号合并一列，编号与请求列默认隐藏。 */
    public function test_generation_runs_table_is_compact(): void
    {
        $this->actingAs(User::factory()->create());
        $run = ChapterGenerationRun::factory()->create(['next_step' => 'draft']);
        Livewire::test(GenerationRuns::class)->assertDontSee('章节运行历史')->assertSee('正文生成')->assertSee('第'.$run->chapter->number.'章')
            ->assertCanNotRenderTableColumn('id')->assertCanNotRenderTableColumn('parent_run_id')->assertCanNotRenderTableColumn('requests_used');
        B04ContentTest::clearFixtures();
    }

    /** 单本小说子导航按“规划、人物与世界、正文、交付、更多”排列；诊断与低频页面收进“更多”，并带默认收起脚本。 */
    public function test_record_sub_navigation_groups_low_frequency_pages_under_more(): void
    {
        $this->actingAs(User::factory()->create());
        $novel = Novel::factory()->create();
        $page = Livewire::test(ViewNovel::class, ['record' => $novel->id])->instance();
        $groups = collect($page->getCachedSubNavigation())->mapWithKeys(fn ($group): array => [(string) $group->getLabel() => collect($group->getItems())->map(fn ($item): string => (string) $item->getLabel())->all()]);
        $this->assertSame(['', '规划', '人物与世界', '正文', '交付', '更多'], $groups->keys()->all());
        $this->assertSame(['设定与蓝图', '章节大纲', '场景规划'], $groups['规划']);
        $this->assertSame(['原始要求与覆盖', '卷与故事弧', '运行与恢复', '上下文检查器', '检索实验室', '费用明细', '提交与基线', '故事状态回放'], $groups['更多']);
        $this->get(NovelResource::getUrl('view', ['record' => $novel]))->assertOk()->assertSee('data-nf-more', false)->assertSee('novel-forge:sub-nav-more-collapsed', false);
    }

    /** 区块说明只保留一句结论，完整说明进入标题旁的「?」提示。 */
    public function test_long_section_descriptions_move_into_help_hint(): void
    {
        $this->actingAs(User::factory()->create());
        $html = Livewire::test(ManageModelPrices::class)->assertSee('按实际响应模型匹配单价，只登记官方核实过的价格。')->html();
        $this->assertStringContainsString('gpt-4.1-mini-2025-04-14', $html);
    }
}
