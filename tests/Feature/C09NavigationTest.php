<?php

namespace Tests\Feature;

use App\Filament\Pages\ProductionOverview;
use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Novel;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
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
        // UI01～UI32 覆盖清单已移到系统健康页，仅供开发验收查看。
        $this->get(SystemHealth::getUrl())->assertOk()->assertSee('UI01')->assertSee('UI32');
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
}
