<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Domains\Staff\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ForgetInvalidAuthenticatedSession;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TenancyMiddleware;
use CraftForge\FilamentLanguageSwitcher\FilamentLanguageSwitcherPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Platform “admin” Filament panel surface.
 *
 * Configured for the platform tenant using {@see TenancyMiddleware}.
 */
final class AdminPanelProvider extends PanelProvider
{
    /**
     * Build and configure the admin panel (id/path/resources/pages/widgets/middleware).
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->plugins([
                FilamentLanguageSwitcherPlugin::make()
                    ->locales(['en', 'ru', 'uk'])
                    // Default USER_MENU_BEFORE is easy to miss in the topbar; this hook is inside the topbar actions area.
                    ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER),
            ])
            ->font('Poppins')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                TenancyMiddleware::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ForgetInvalidAuthenticatedSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Must run after StartSession (see first middleware() block above). A separate append keeps order correct.
            ->middleware([
                SetLocale::class,
            ], isPersistent: true)
            ->authMiddleware([
                TenancyMiddleware::class,
                Authenticate::class,
                EnsureUserIsActive::class,
            ]);
    }
}
