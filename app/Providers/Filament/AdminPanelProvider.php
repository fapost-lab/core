<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Domains\Staff\Http\Middleware\EndExpiredSupportSession;
use App\Domains\Staff\Http\Middleware\EnsureUserIsActive;
use App\Domains\Tenancy\Support\TenantHost;
use App\Filament\Support\AccessNoticeBanner;
use App\Filament\Support\SupportAccessBanner;
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
     * Шкала «шалфей» (Warm Minimal), привязанная к конструктору: shade 600 = #5a6e58.
     * OKLCH сохраняет единый оттенок (H≈142.5) и приглушённую насыщенность по всей шкале.
     *
     * @var array<int, string>
     */
    private const SAGE_PALETTE = [
        50  => 'oklch(0.965 0.019 142.5)',
        100 => 'oklch(0.930 0.019 142.5)',
        200 => 'oklch(0.875 0.029 142.5)',
        300 => 'oklch(0.800 0.042 142.5)',
        400 => 'oklch(0.660 0.042 142.5)',
        500 => 'oklch(0.550 0.042 142.5)',
        600 => 'oklch(0.515 0.042 142.5)',
        700 => 'oklch(0.405 0.042 142.5)',
        800 => 'oklch(0.340 0.042 142.5)',
        900 => 'oklch(0.285 0.025 142.5)',
        950 => 'oklch(0.210 0.025 142.5)',
    ];

    /**
     * Build and configure the admin panel (id/path/resources/pages/widgets/middleware).
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // The tenant panels live on tenant hosts only. The base domain is
            // reserved: a control plane for managing tenants belongs there, and a
            // tenant panel answering on it would be a surface nobody asked for.
            // In `host` mode there is no single host: see TenantHost::panelDomain().
            ->domain(TenantHost::panelDomain())
            ->brandLogo(fn (): string => asset('logo.png'))
            ->brandLogoHeight('1.75rem')
            ->favicon(asset('favicon.png'))
            ->login()
            ->topNavigation()
            ->plugins([
                FilamentLanguageSwitcherPlugin::make()
                    ->locales(['en', 'ru', 'uk'])
                    // Persist the choice (same cookie as SetLocale) so it never reverts on its own.
                    ->rememberLocale(365)
                    // Default USER_MENU_BEFORE is easy to miss in the topbar; this hook is inside the topbar actions area.
                    ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER),
            ])
            ->font('DM Sans')
            ->viteTheme('resources/css/filament/theme.css')
            ->colors([
                // Warm Minimal: глубокий шалфей (600 = #5a6e58 из конструктора) + тёплая нейтраль.
                'primary' => self::SAGE_PALETTE,
                'gray'    => Color::Stone,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
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
            // Livewire replays only persistent middleware for component updates, and
            // /livewire/update itself is served on the base domain as well. Without this
            // a panel component posted there would run with no tenant instead of 404.
            ->persistentMiddleware([TenancyMiddleware::class])
            ->middleware([
                SetLocale::class,
                EndExpiredSupportSession::class,
            ], isPersistent: true)
            ->renderHook(PanelsRenderHook::BODY_START, static fn (): string => SupportAccessBanner::render())
            ->renderHook(PanelsRenderHook::BODY_START, static fn (): string => AccessNoticeBanner::render())
            ->authMiddleware([
                TenancyMiddleware::class,
                Authenticate::class,
                EnsureUserIsActive::class,
            ]);
    }
}
