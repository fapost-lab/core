<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Http\Middleware\EnsureUserIsActive;
use App\Filament\Assistant\Pages\AssistantDashboard;
use App\Http\Controllers\Filament\AssistantPanelHomeController;
use App\Http\Middleware\ForgetInvalidAuthenticatedSession;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TenancyMiddleware;
use CraftForge\FilamentLanguageSwitcher\FilamentLanguageSwitcherPlugin;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Assistant operational Filament panel (UI “tenant” = {@see Assistant}, independent of platform schema tenant).
 *
 * HTTP stack order: base web middleware → {@see TenancyMiddleware} (platform tenant) → {@see Authenticate} →
 * {@see EnsureUserIsActive} → Filament {@see \Filament\Http\Middleware\IdentifyTenant} (resolves
 * {@code assistant/{tenant}}; requires an authenticated user). Same rule as legacy {@code ResolveAssistantMiddleware}:
 * authentication must run before assistant UI identity resolution.
 */
final class AssistantPanelProvider extends PanelProvider
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
     * Build and configure the assistant operational panel (id/path/tenant/middleware).
     */
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('assistant')
            ->path('assistant')
            ->login()
            ->spa()
            ->sidebarFullyCollapsibleOnDesktop()
            ->font('DM Sans')
            ->viteTheme('resources/css/filament/theme.css')
            ->colors([
                // Warm Minimal: глубокий шалфей (600 = #5a6e58 из конструктора) + тёплая нейтраль.
                'primary' => self::SAGE_PALETTE,
                'gray'    => Color::Stone,
            ])
            ->plugins([
                FilamentLanguageSwitcherPlugin::make()
                    ->locales(['en', 'ru', 'uk'])
                    // Persist the choice (same cookie as SetLocale) so it never reverts on its own.
                    ->rememberLocale(365)
                    // Default USER_MENU_BEFORE is easy to miss in the topbar; this hook is inside the topbar actions area.
                    ->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER),
            ])
            ->discoverResources(
                in: app_path('Filament/Assistant/Resources'),
                for: 'App\\Filament\\Assistant\\Resources'
            )
            ->discoverPages(in: app_path('Filament/Assistant/Pages'), for: 'App\\Filament\\Assistant\\Pages')
            ->homeUrl(function (): ?string {
                $tenant = Filament::getTenant();

                return $tenant ? AssistantDashboard::getUrl(tenant: $tenant) : null;
            })
            ->authenticatedTenantRoutes(function (Panel $_panel): void {
                Route::get('/', AssistantPanelHomeController::class);
            })
            ->tenant(Assistant::class, slugAttribute: 'id', ownershipRelationship: 'assistants')
            ->tenantMenuItems([
                Action::make('back_to_admin')
                    ->label(__('assistant.switcher.back_to_admin'))
                    ->url(fn (): string => route('filament.admin.resources.assistants.index'))
                    ->icon(Heroicon::OutlinedArrowLeftOnRectangle)
                    ->sort(100),
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => (string)view('filament.assistant.partials.flow-row-colors')
            )
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
