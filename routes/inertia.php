<?php

declare(strict_types=1);

use App\Domains\Tenancy\Support\TenantHost;
use App\Http\Controllers\Console\Auth\LoginController;
use App\Http\Controllers\Console\Auth\LogoutController;
use App\Http\Controllers\Console\BroadcastController;
use App\Http\Controllers\Console\ChannelController;
use App\Http\Controllers\Console\ContactController;
use App\Http\Controllers\Console\ContactGroupController;
use App\Http\Controllers\Console\ContactSegmentController;
use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\Console\FlowController;
use App\Http\Controllers\Console\FlowGroupController;
use App\Http\Controllers\Console\FlowLogController;
use App\Http\Controllers\Console\FlowSessionController;
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

        // Channels: the assistant's transport endpoints; rotation is its own write.
        Route::get('assistant/{tenant}/channels', [ChannelController::class, 'index'])->name('filament.assistant.resources.channels.index');
        Route::get('assistant/{tenant}/channels/create', [ChannelController::class, 'create'])->name('filament.assistant.resources.channels.create');
        Route::get('assistant/{tenant}/channels/{record}/edit', [ChannelController::class, 'edit'])->name('filament.assistant.resources.channels.edit');
        Route::post('assistant/{tenant}/channels', [ChannelController::class, 'store'])->name('console.channels.store');
        Route::put('assistant/{tenant}/channels/{record}', [ChannelController::class, 'update'])->name('console.channels.update');
        Route::post('assistant/{tenant}/channels/{record}/rotate-webhook', [ChannelController::class, 'rotateWebhook'])->name('console.channels.rotate-webhook');
        Route::post('assistant/{tenant}/channels/{record}/register-webhook', [ChannelController::class, 'registerWebhook'])->name('console.channels.register-webhook');
        Route::delete('assistant/{tenant}/channels/{record}', [ChannelController::class, 'destroy'])->name('console.channels.destroy');

        // Contacts: tenant-level records shown under the assistant whose channels they wrote to; read-only except tags and group membership.
        Route::get('assistant/{tenant}/contacts', [ContactController::class, 'index'])->name('filament.assistant.resources.contacts.index');
        Route::get('assistant/{tenant}/contacts/{record}', [ContactController::class, 'show'])->name('filament.assistant.resources.contacts.view');
        // The whole set is sent, so a repeated request changes nothing.
        Route::put('assistant/{tenant}/contacts/{record}/tags', [ContactController::class, 'updateTags'])->name('console.contacts.tags');
        Route::put('assistant/{tenant}/contacts/{record}/groups', [ContactController::class, 'updateGroups'])->name('console.contacts.groups');

        // Contact groups: tenant-level, listed under the assistant's menu like Filament's resource was.
        Route::get('assistant/{tenant}/contact-groups', [ContactGroupController::class, 'index'])->name('filament.assistant.resources.contact-groups.index');
        Route::get('assistant/{tenant}/contact-groups/create', [ContactGroupController::class, 'create'])->name('filament.assistant.resources.contact-groups.create');
        Route::get('assistant/{tenant}/contact-groups/{record}/edit', [ContactGroupController::class, 'edit'])->name('filament.assistant.resources.contact-groups.edit');
        Route::post('assistant/{tenant}/contact-groups', [ContactGroupController::class, 'store'])->name('console.contact-groups.store');
        Route::delete('assistant/{tenant}/contact-groups', [ContactGroupController::class, 'destroyMany'])->name('console.contact-groups.destroy-many');
        Route::put('assistant/{tenant}/contact-groups/{record}', [ContactGroupController::class, 'update'])->name('console.contact-groups.update');
        Route::delete('assistant/{tenant}/contact-groups/{record}', [ContactGroupController::class, 'destroy'])->name('console.contact-groups.destroy');

        // Contact segments: tenant-level saved rules, listed under the assistant's menu like Filament's resource was.
        Route::get('assistant/{tenant}/contact-segments', [ContactSegmentController::class, 'index'])->name('filament.assistant.resources.contact-segments.index');
        Route::get('assistant/{tenant}/contact-segments/create', [ContactSegmentController::class, 'create'])->name('filament.assistant.resources.contact-segments.create');
        Route::get('assistant/{tenant}/contact-segments/{record}/edit', [ContactSegmentController::class, 'edit'])->name('filament.assistant.resources.contact-segments.edit');
        Route::post('assistant/{tenant}/contact-segments', [ContactSegmentController::class, 'store'])->name('console.contact-segments.store');
        Route::put('assistant/{tenant}/contact-segments/{record}', [ContactSegmentController::class, 'update'])->name('console.contact-segments.update');
        Route::delete('assistant/{tenant}/contact-segments/{record}', [ContactSegmentController::class, 'destroy'])->name('console.contact-segments.destroy');
        // A recount writes the size snapshot, so it is a POST.
        Route::post('assistant/{tenant}/contact-segments/{record}/count', [ContactSegmentController::class, 'refreshCount'])->name('console.contact-segments.count');

        // Flow groups and flows: both belong to the assistant in the URL. Creating a flow ends in the builder.
        Route::get('assistant/{tenant}/flow-groups', [FlowGroupController::class, 'index'])->name('filament.assistant.resources.flow-groups.index');
        Route::get('assistant/{tenant}/flow-groups/create', [FlowGroupController::class, 'create'])->name('filament.assistant.resources.flow-groups.create');
        Route::get('assistant/{tenant}/flow-groups/{record}/edit', [FlowGroupController::class, 'edit'])->name('filament.assistant.resources.flow-groups.edit');
        Route::post('assistant/{tenant}/flow-groups', [FlowGroupController::class, 'store'])->name('console.flow-groups.store');
        // A group made from a flow's form, without leaving it.
        Route::post('assistant/{tenant}/flow-groups/inline', [FlowGroupController::class, 'storeInline'])->name('console.flow-groups.store-inline');
        Route::delete('assistant/{tenant}/flow-groups', [FlowGroupController::class, 'destroyMany'])->name('console.flow-groups.destroy-many');
        Route::put('assistant/{tenant}/flow-groups/{record}', [FlowGroupController::class, 'update'])->name('console.flow-groups.update');
        Route::delete('assistant/{tenant}/flow-groups/{record}', [FlowGroupController::class, 'destroy'])->name('console.flow-groups.destroy');

        Route::get('assistant/{tenant}/flows', [FlowController::class, 'index'])->name('filament.assistant.resources.flows.index');
        Route::get('assistant/{tenant}/flows/create', [FlowController::class, 'create'])->name('filament.assistant.resources.flows.create');
        Route::get('assistant/{tenant}/flows/{record}/edit', [FlowController::class, 'edit'])->name('filament.assistant.resources.flows.edit');
        Route::post('assistant/{tenant}/flows', [FlowController::class, 'store'])->name('console.flows.store');
        Route::delete('assistant/{tenant}/flows', [FlowController::class, 'destroyMany'])->name('console.flows.destroy-many');
        Route::put('assistant/{tenant}/flows/{record}', [FlowController::class, 'update'])->name('console.flows.update');
        // The state is sent (`active`), not toggled, so a repeated request changes nothing.
        Route::patch('assistant/{tenant}/flows/{record}/active', [FlowController::class, 'updateActivity'])->name('console.flows.activity');
        Route::delete('assistant/{tenant}/flows/{record}', [FlowController::class, 'destroy'])->name('console.flows.destroy');

        // Broadcasts reach real people. `send` carries the revision the person confirmed and starts a draft at most once;
        // `cancel` and `delete` are conditional on the status; `reach` is a read, throttled because each call counts in the database.
        Route::get('assistant/{tenant}/broadcasts', [BroadcastController::class, 'index'])->name('filament.assistant.resources.broadcasts.index');
        Route::get('assistant/{tenant}/broadcasts/create', [BroadcastController::class, 'create'])->name('filament.assistant.resources.broadcasts.create');
        Route::get('assistant/{tenant}/broadcasts/reach', [BroadcastController::class, 'reach'])->middleware('throttle:30,1')->name('console.broadcasts.reach');
        Route::get('assistant/{tenant}/broadcasts/{record}/edit', [BroadcastController::class, 'edit'])->name('filament.assistant.resources.broadcasts.edit');
        Route::post('assistant/{tenant}/broadcasts', [BroadcastController::class, 'store'])->name('console.broadcasts.store');
        Route::put('assistant/{tenant}/broadcasts/{record}', [BroadcastController::class, 'update'])->name('console.broadcasts.update');
        Route::post('assistant/{tenant}/broadcasts/{record}/send', [BroadcastController::class, 'send'])->name('console.broadcasts.send');
        Route::post('assistant/{tenant}/broadcasts/{record}/cancel', [BroadcastController::class, 'cancel'])->name('console.broadcasts.cancel');
        Route::delete('assistant/{tenant}/broadcasts/{record}', [BroadcastController::class, 'destroy'])->name('console.broadcasts.destroy');

        // Flow sessions and the flow log: read-only, kept current live or by polling. Every log read is bounded in time.
        Route::get('assistant/{tenant}/flow-sessions', [FlowSessionController::class, 'index'])->name('filament.assistant.resources.flow-sessions.index');
        Route::get('assistant/{tenant}/flow-sessions/{record}', [FlowSessionController::class, 'show'])->name('filament.assistant.resources.flow-sessions.view');
        Route::get('assistant/{tenant}/flow-logs', [FlowLogController::class, 'index'])->name('filament.assistant.resources.flow-logs.index');
        Route::get('assistant/{tenant}/flow-logs/{record}', [FlowLogController::class, 'show'])->name('filament.assistant.resources.flow-logs.view');
    });
});
