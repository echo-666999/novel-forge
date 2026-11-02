<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    /** 登录测试仅迁移应用表，避免执行仅适用于 PostgreSQL 的向量扩展迁移。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A01 验收当前 local 环境；数据库和会话仍使用 PHPUnit 的隔离配置。
        config(['app.env' => 'local']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_guests_are_redirected_to_the_working_login_page(): void
    {
        $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
        $this->get('/admin/login')->assertOk()->assertSeeLivewire(Login::class);
        $this->assertGuest();
    }

    public function test_valid_credentials_allow_access_to_the_dashboard(): void
    {
        // 账号仅存在于 SQLite 内存数据库，不使用或修改真实管理员账号。
        $user = User::factory()->create();

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect(Filament::getUrl());

        $this->assertAuthenticatedAs($user);
        $this->get('/admin')->assertOk()->assertSee('仪表板');
    }

    public function test_incorrect_password_does_not_authenticate_the_user(): void
    {
        $user = User::factory()->create();

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'incorrect-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email'])
            ->assertNoRedirect();

        $this->assertGuest();
        $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    }
}
