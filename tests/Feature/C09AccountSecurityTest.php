<?php

namespace Tests\Feature;

use App\Filament\Auth\EditProfile;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** 账户敏感动作必须重新验证密码，旧会话凭据不可继续使用。 */
class C09AccountSecurityTest extends TestCase
{
    use DatabaseMigrations;

    /** 隔离业务迁移及设置。 */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => ['database/migrations', 'database/settings']];
    }

    /** 错误密码不改变凭据；正确密码产生新散列使旧会话失效。 */
    public function test_device_revocation_requires_password_and_invalidates_old_session(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create(['password' => Hash::make('test-password')]);
        $oldHash = $user->password;
        $this->actingAs($user);
        Livewire::test(EditProfile::class)->callAction('logoutOtherDevices', ['password' => 'wrong'])
            ->assertHasActionErrors();
        $this->assertSame($oldHash, $user->fresh()->password);
        Livewire::test(EditProfile::class)->callAction('logoutOtherDevices', ['password' => 'test-password'])
            ->assertHasNoActionErrors()->assertNotified('其他设备会话已撤销');
        $this->assertNotSame($oldHash, $user->fresh()->password);
        $this->assertTrue(Hash::check('test-password', $user->fresh()->password));
        // 模拟旧设备已通过认证但仍保存旧密码散列的下一次请求。
        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])
            ->get('/admin')->assertRedirect('/admin/login');
    }
}
