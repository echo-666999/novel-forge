<?php

namespace App\Filament\Auth;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** 账户安全页：复用框架密码校验和会话中间件使其他设备失效。 */
class EditProfile extends \Filament\Auth\Pages\EditProfile
{
    /** 敏感操作再次验证密码，密码不写日志、不存入审计。
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            $this->logoutOtherDevicesAction(),
        ];
    }

    /** 注册可由账户页面调用的敏感动作，服务端始终重新校验密码。 */
    public function logoutOtherDevicesAction(): Action
    {
        return Action::make('logoutOtherDevices')->label('退出其他设备')
            ->modalDescription('其他设备的后台会话将在下次请求时退出。当前设备保持登录。')
            ->schema([TextInput::make('password')->label('当前密码')->password()->required()->autocomplete('current-password')])
            ->action(function (array $data): void {
                $key = 'logout-other-devices:'.Filament::auth()->id();
                if (RateLimiter::tooManyAttempts($key, 5)) {
                    throw ValidationException::withMessages(['password' => '操作过于频繁，请一分钟后再试。']);
                }
                RateLimiter::hit($key, 60);
                if (! Hash::check($data['password'], Filament::auth()->user()->getAuthPassword())) {
                    throw ValidationException::withMessages(['password' => '当前密码不正确。']);
                }
                // 框架重新散列密码，AuthenticateSession 校验旧会话并使其失效。
                Filament::auth()->logoutOtherDevices($data['password']);
                Notification::make()->success()->title('其他设备会话已撤销')->send();
            });
    }
}
