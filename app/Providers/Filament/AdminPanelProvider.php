<?php

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** 私有后台面板，注册登录、账户安全及业务导航；生产总览即后台首页。 */
class AdminPanelProvider extends PanelProvider
{
    /** 会话认证中间件用于密码变更和撤销其他设备后的凭据失效。 */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(config('app.name'))
            ->login()
            ->profile(EditProfile::class)
            ->colors([
                'primary' => Color::Amber,
            ])
            // PRD 22.1 导航分组：创作 → 生产与质量 → 配置与运维。
            ->navigationGroups(['创作工作台', '生产与质量', '配置与运维'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // ->sidebarWidth('sm')
            ->maxContentWidth('full')
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
