<?php

declare(strict_types=1);

use App\Domains\Tenancy\Support\TenantHost;
use App\Http\Controllers\Console\Auth\LoginController;
use App\Http\Controllers\Console\Auth\LogoutController;
use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\Console\LocaleController;
use App\Http\Middleware\ForgetInvalidAuthenticatedSession;
use App\Http\Middleware\SetConsoleRootView;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The Inertia console
|--------------------------------------------------------------------------
|
| Loaded by bootstrap/app.php only when `config('ui.inertia')` is on, and always after the Filament
| panels have registered their routes.
|
| A screen that moves off Filament is declared here with the SAME domain, method, URI and name as the
| Filament route it replaces. The route collection keys routes by method + domain + URI, so the later
| declaration wins, also under `route:cache`, and every `route()`, `getUrl()` and redirect by name now
| reaches the new screen. Two traps:
|
|  - a different parameter name in the URI (`{assistant}` for `{tenant}`) is a different key and
|    replaces nothing: Filament keeps answering, silently. Keep `{tenant}` and `{record}`;
|  - the name must be the same, or the name of the replaced route disappears.
|
| `ConsoleRouteInterceptionTest` pins which action answers each intercepted name.
|
| Stacks (defined in bootstrap/app.php):
|  - `admin`   : web + tenant + session checks + account checks, for tenant-wide screens (`admin/*`);
|  - `console` : `admin` + ResolveCurrentAssistant:tenant, for `assistant/{tenant}/*`.
| Every new action authorizes (see ConsoleAuthorizationTest).
*/

Route::domain(TenantHost::panelDomain())->group(function (): void {
    // Sign-in is for visitors who are not signed in yet, so it takes the tenant and the session, not `auth`.
    Route::middleware(['web', 'tenant', ForgetInvalidAuthenticatedSession::class, SetConsoleRootView::class])->group(function (): void {
        Route::get('admin/login', [LoginController::class, 'show'])->name('filament.admin.auth.login');
        Route::post('admin/login', [LoginController::class, 'store'])->name('console.auth.login.attempt');
        Route::get('assistant/login', [LoginController::class, 'assistant'])->name('filament.assistant.auth.login');
        Route::post('console/logout', LogoutController::class)->name('console.auth.logout');
    });

    // Tenant-wide screens and the interface language, behind the `admin` stack.
    Route::middleware('admin')->group(function (): void {
        Route::post('console/locale', LocaleController::class)->name('console.locale.update');
    });

    // An assistant's screens: `{tenant}` is the assistant, under the name Filament gave the parameter.
    Route::middleware('console')->group(function (): void {
        Route::get('assistant/{tenant}/dashboard', DashboardController::class)->name('filament.assistant.pages.dashboard');
    });
});
