<?php

declare(strict_types=1);

use App\Domains\Tenancy\Support\TenantHost;
use App\Http\Controllers\Admin\AssistantChannelController as AdminAssistantChannelController;
use App\Http\Controllers\Admin\AssistantController as AdminAssistantController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\MediaController as AdminMediaController;
use App\Http\Controllers\Admin\MediaFolderController as AdminMediaFolderController;
use App\Http\Controllers\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\Admin\SearchController as AdminSearchController;
use App\Http\Controllers\Admin\SupportAccessController as AdminSupportAccessController;
use App\Http\Controllers\Admin\TenantSettingsController as AdminTenantSettingsController;
use App\Http\Controllers\Admin\TranslationController as AdminTranslationController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Console\ApplicationRailController;
use App\Http\Controllers\Console\AssistantHomeController;
use App\Http\Controllers\Console\AssistantSettingsController;
use App\Http\Controllers\Console\Auth\LoginController;
use App\Http\Controllers\Console\Auth\LogoutController;
use App\Http\Controllers\Console\BroadcastController;
use App\Http\Controllers\Console\ChannelController;
use App\Http\Controllers\Console\ContactController;
use App\Http\Controllers\Console\ContactGroupController;
use App\Http\Controllers\Console\ContactSegmentController;
use App\Http\Controllers\Console\ConversationController;
use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\Console\FlowController;
use App\Http\Controllers\Console\FlowGroupController;
use App\Http\Controllers\Console\FlowLogController;
use App\Http\Controllers\Console\FlowSessionController;
use App\Http\Controllers\Console\LocaleController;
use App\Http\Controllers\Console\NotificationController;
use App\Http\Controllers\Console\TranslationController;
use App\Http\Middleware\ForgetInvalidAuthenticatedSession;
use App\Http\Middleware\SetConsoleRootView;
use App\Http\Shell\SolutionRoutes;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The Inertia console
|--------------------------------------------------------------------------
|
| Always loaded by bootstrap/app.php. The admin screens live under `admin/*`, an
| assistant's under `assistant/{assistant}/*`, on the panel domain of the tenant.
|
| Every route is named `console.*`: the admin screens `console.admin.<resource>.<action>`, an assistant's
| `console.<resource>.<action>`, reads `index|create|show|edit`, writes their own verbs. The URIs are those of the
| Filament panels this console replaced, so bookmarks keep working. `{assistant}` is the assistant's key, `{record}`
| the screen's record. `ConsoleRouteInterceptionTest` pins which action answers each name.
|
| Stacks (defined in bootstrap/app.php):
|  - `admin`   : web + tenant + session checks + account checks, for tenant-wide screens (`admin/*`);
|  - `console` : `admin` + ResolveCurrentAssistant, for `assistant/{assistant}/*`;
|  - `solution`, `solution.assistant` : the two above for the routes of Solutions, registered at the end.
| Every new action authorizes (see ConsoleAuthorizationTest).
*/

Route::domain(TenantHost::panelDomain())->group(function (): void {
    // Sign-in is for visitors who are not signed in yet, so it takes the tenant and the session, not `auth`.
    Route::middleware(['web', 'tenant', ForgetInvalidAuthenticatedSession::class, SetConsoleRootView::class])->group(function (): void {
        Route::get('admin/login', [LoginController::class, 'show'])->name('console.auth.login');
        Route::post('admin/login', [LoginController::class, 'store'])->name('console.auth.login.attempt');
        Route::get('assistant/login', [LoginController::class, 'assistant'])->name('console.auth.assistant-login');
        Route::post('console/logout', LogoutController::class)->name('console.auth.logout');
    });

    // Tenant-wide screens and the interface language, behind the `admin` stack.
    Route::middleware('admin')->group(function (): void {
        Route::post('console/locale', LocaleController::class)->name('console.locale.update');

        // App switcher: the user's own rail (which applications are pinned, in what order), in both panels.
        Route::put('console/applications/rail', ApplicationRailController::class)->name('console.applications.rail.update');

        // The notification bell: the user's own in-app notifications, in both panels. The count is polled; every route is throttled.
        Route::get('console/notifications', [NotificationController::class, 'index'])->middleware('throttle:60,1')->name('console.notifications.index');
        Route::get('console/notifications/count', [NotificationController::class, 'count'])->middleware('throttle:60,1')->name('console.notifications.count');
        Route::post('console/notifications/read', [NotificationController::class, 'readAll'])->middleware('throttle:60,1')->name('console.notifications.read-all');
        Route::post('console/notifications/{notification}/read', [NotificationController::class, 'read'])->middleware('throttle:60,1')->name('console.notifications.read');

        // Translations: the tenant's overrides of the system catalog, one layer. `{key}` is a catalog key (dots included).
        Route::get('admin/translations', [AdminTranslationController::class, 'index'])->name('console.admin.translations.index');
        Route::put('admin/translations/{key}', [AdminTranslationController::class, 'update'])->name('console.admin.translations.update');
        Route::delete('admin/translations/{key}', [AdminTranslationController::class, 'reset'])->name('console.admin.translations.reset');

        // Staff users: an invitation instead of a password; the activation state is sent, not toggled, so a repeated request changes nothing.
        Route::get('admin/users', [AdminUserController::class, 'index'])->name('console.admin.users.index');
        Route::get('admin/users/create', [AdminUserController::class, 'create'])->name('console.admin.users.create');
        Route::get('admin/users/{record}/edit', [AdminUserController::class, 'edit'])->name('console.admin.users.edit');
        Route::post('admin/users', [AdminUserController::class, 'store'])->name('console.admin.users.store');
        Route::delete('admin/users', [AdminUserController::class, 'destroyMany'])->name('console.admin.users.destroy-many');
        Route::put('admin/users/{record}', [AdminUserController::class, 'update'])->name('console.admin.users.update');
        Route::patch('admin/users/{record}/active', [AdminUserController::class, 'updateActivity'])->name('console.admin.users.activity');
        Route::post('admin/users/{record}/resend-activation', [AdminUserController::class, 'resendActivation'])->name('console.admin.users.resend-activation');
        Route::delete('admin/users/{record}', [AdminUserController::class, 'destroy'])->name('console.admin.users.destroy');

        // Staff roles: permissions chosen from the catalogue; a system role keeps its name and is never deleted.
        Route::get('admin/roles', [AdminRoleController::class, 'index'])->name('console.admin.roles.index');
        Route::get('admin/roles/create', [AdminRoleController::class, 'create'])->name('console.admin.roles.create');
        Route::get('admin/roles/{record}/edit', [AdminRoleController::class, 'edit'])->name('console.admin.roles.edit');
        Route::post('admin/roles', [AdminRoleController::class, 'store'])->name('console.admin.roles.store');
        Route::delete('admin/roles', [AdminRoleController::class, 'destroyMany'])->name('console.admin.roles.destroy-many');
        Route::put('admin/roles/{record}', [AdminRoleController::class, 'update'])->name('console.admin.roles.update');
        Route::delete('admin/roles/{record}', [AdminRoleController::class, 'destroy'])->name('console.admin.roles.destroy');
        // Assistants: the tenant's assistants with their channels. `{record}` is the assistant; a channel is created on
        // the assistant's console, as it was, and changed here on the console's channel form.
        Route::get('admin/assistants', [AdminAssistantController::class, 'index'])->name('console.admin.assistants.index');
        Route::get('admin/assistants/create', [AdminAssistantController::class, 'create'])->name('console.admin.assistants.create');
        Route::get('admin/assistants/{record}', [AdminAssistantController::class, 'show'])->name('console.admin.assistants.show');
        Route::get('admin/assistants/{record}/edit', [AdminAssistantController::class, 'edit'])->name('console.admin.assistants.edit');
        Route::post('admin/assistants', [AdminAssistantController::class, 'store'])->name('console.admin.assistants.store');
        Route::put('admin/assistants/{record}', [AdminAssistantController::class, 'update'])->name('console.admin.assistants.update');
        Route::delete('admin/assistants/{record}', [AdminAssistantController::class, 'destroy'])->name('console.admin.assistants.destroy');
        Route::get('admin/assistants/{record}/channels/{channel}/edit', [AdminAssistantChannelController::class, 'edit'])->name('console.admin.assistants.channels.edit');
        Route::put('admin/assistants/{record}/channels/{channel}', [AdminAssistantChannelController::class, 'update'])->name('console.admin.assistants.channels.update');
        Route::post('admin/assistants/{record}/channels/{channel}/rotate-webhook', [AdminAssistantChannelController::class, 'rotateWebhook'])->name('console.admin.assistants.channels.rotate-webhook');
        Route::post('admin/assistants/{record}/channels/{channel}/register-webhook', [AdminAssistantChannelController::class, 'registerWebhook'])->name('console.admin.assistants.channels.register-webhook');
        Route::delete('admin/assistants/{record}/channels/{channel}', [AdminAssistantChannelController::class, 'destroy'])->name('console.admin.assistants.channels.destroy');

        // Dashboard and search: the admin home with the tenant's figures, and the shell's search palette (JSON, asked as
        // the user types, so throttled). Filament's global search had no route; the palette's is the console's own.
        Route::get('admin', AdminDashboardController::class)->name('console.admin.dashboard');
        Route::get('admin/search', AdminSearchController::class)->middleware('throttle:60,1')->name('console.admin.search');

        // The media library: files of the open folder (`filter[folder]`), the trash, folders; bytes only through signed `media.files.raw`.
        Route::get('admin/media', [AdminMediaController::class, 'index'])->name('console.admin.media.index');
        Route::post('admin/media', [AdminMediaController::class, 'upload'])->name('console.admin.media.upload');
        Route::put('admin/media/move', [AdminMediaController::class, 'move'])->name('console.admin.media.move');
        Route::delete('admin/media', [AdminMediaController::class, 'destroyMany'])->name('console.admin.media.destroy-many');
        Route::post('admin/media/folders', [AdminMediaFolderController::class, 'store'])->name('console.admin.media.folders.store');
        Route::put('admin/media/folders/{folder}', [AdminMediaFolderController::class, 'update'])->name('console.admin.media.folders.update');
        Route::delete('admin/media/folders/{folder}', [AdminMediaFolderController::class, 'destroy'])->name('console.admin.media.folders.destroy');
        Route::get('admin/media/{record}', [AdminMediaController::class, 'show'])->name('console.admin.media.show');
        Route::patch('admin/media/{record}', [AdminMediaController::class, 'update'])->name('console.admin.media.update');
        Route::delete('admin/media/{record}', [AdminMediaController::class, 'destroy'])->name('console.admin.media.destroy');
        Route::post('admin/media/{record}/restore', [AdminMediaController::class, 'restore'])->name('console.admin.media.restore');
        Route::delete('admin/media/{record}/force', [AdminMediaController::class, 'forceDestroy'])->name('console.admin.media.force');
        // Tenant settings: languages with the base-language lock, runtime limits, broadcast pacing; one form saved whole.
        Route::get('admin/tenant-settings', [AdminTenantSettingsController::class, 'edit'])->name('console.admin.tenant-settings.edit');
        Route::put('admin/tenant-settings', [AdminTenantSettingsController::class, 'update'])->name('console.admin.tenant-settings.update');
        // Support access log: read only, and never a Filament screen, so the name is the console's own.
        Route::get('admin/support-access', [AdminSupportAccessController::class, 'index'])->name('console.admin.support-access.index');

        // `assistant` is no screen but may be bookmarked: it sends on to an assistant the user may open.
        Route::get('assistant', [AssistantHomeController::class, 'index'])->name('console.entry');
    });

    // An assistant's screens: `{assistant}` is the assistant's key, resolved by the `console` stack.
    Route::middleware('console')->group(function (): void {
        // The assistant's own address sends on to its dashboard, as Filament's panel home did.
        Route::get('assistant/{assistant}', [AssistantHomeController::class, 'show'])->name('console.home');
        Route::get('assistant/{assistant}/dashboard', DashboardController::class)->name('console.dashboard');

        // Channels: the assistant's transport endpoints; rotation is its own write.
        Route::get('assistant/{assistant}/channels', [ChannelController::class, 'index'])->name('console.channels.index');
        Route::get('assistant/{assistant}/channels/create', [ChannelController::class, 'create'])->name('console.channels.create');
        Route::get('assistant/{assistant}/channels/{record}/edit', [ChannelController::class, 'edit'])->name('console.channels.edit');
        Route::post('assistant/{assistant}/channels', [ChannelController::class, 'store'])->name('console.channels.store');
        Route::put('assistant/{assistant}/channels/{record}', [ChannelController::class, 'update'])->name('console.channels.update');
        Route::post('assistant/{assistant}/channels/{record}/rotate-webhook', [ChannelController::class, 'rotateWebhook'])->name('console.channels.rotate-webhook');
        Route::post('assistant/{assistant}/channels/{record}/register-webhook', [ChannelController::class, 'registerWebhook'])->name('console.channels.register-webhook');
        Route::delete('assistant/{assistant}/channels/{record}', [ChannelController::class, 'destroy'])->name('console.channels.destroy');

        // Contacts: tenant-level records shown under the assistant whose channels they wrote to; read-only except tags and group membership.
        Route::get('assistant/{assistant}/contacts', [ContactController::class, 'index'])->name('console.contacts.index');
        Route::get('assistant/{assistant}/contacts/{record}', [ContactController::class, 'show'])->name('console.contacts.show');
        // The whole set is sent, so a repeated request changes nothing.
        Route::put('assistant/{assistant}/contacts/{record}/tags', [ContactController::class, 'updateTags'])->name('console.contacts.tags');
        Route::put('assistant/{assistant}/contacts/{record}/groups', [ContactController::class, 'updateGroups'])->name('console.contacts.groups');

        // Contact groups: tenant-level, listed under the assistant's menu like Filament's resource was.
        Route::get('assistant/{assistant}/contact-groups', [ContactGroupController::class, 'index'])->name('console.contact-groups.index');
        Route::get('assistant/{assistant}/contact-groups/create', [ContactGroupController::class, 'create'])->name('console.contact-groups.create');
        Route::get('assistant/{assistant}/contact-groups/{record}/edit', [ContactGroupController::class, 'edit'])->name('console.contact-groups.edit');
        Route::post('assistant/{assistant}/contact-groups', [ContactGroupController::class, 'store'])->name('console.contact-groups.store');
        Route::delete('assistant/{assistant}/contact-groups', [ContactGroupController::class, 'destroyMany'])->name('console.contact-groups.destroy-many');
        Route::put('assistant/{assistant}/contact-groups/{record}', [ContactGroupController::class, 'update'])->name('console.contact-groups.update');
        Route::delete('assistant/{assistant}/contact-groups/{record}', [ContactGroupController::class, 'destroy'])->name('console.contact-groups.destroy');

        // Contact segments: tenant-level saved rules, listed under the assistant's menu like Filament's resource was.
        Route::get('assistant/{assistant}/contact-segments', [ContactSegmentController::class, 'index'])->name('console.contact-segments.index');
        Route::get('assistant/{assistant}/contact-segments/create', [ContactSegmentController::class, 'create'])->name('console.contact-segments.create');
        Route::get('assistant/{assistant}/contact-segments/{record}/edit', [ContactSegmentController::class, 'edit'])->name('console.contact-segments.edit');
        Route::post('assistant/{assistant}/contact-segments', [ContactSegmentController::class, 'store'])->name('console.contact-segments.store');
        Route::put('assistant/{assistant}/contact-segments/{record}', [ContactSegmentController::class, 'update'])->name('console.contact-segments.update');
        Route::delete('assistant/{assistant}/contact-segments/{record}', [ContactSegmentController::class, 'destroy'])->name('console.contact-segments.destroy');
        // A recount writes the size snapshot, so it is a POST.
        Route::post('assistant/{assistant}/contact-segments/{record}/count', [ContactSegmentController::class, 'refreshCount'])->name('console.contact-segments.count');

        // Flow groups and flows: both belong to the assistant in the URL. Creating a flow ends in the builder.
        Route::get('assistant/{assistant}/flow-groups', [FlowGroupController::class, 'index'])->name('console.flow-groups.index');
        Route::get('assistant/{assistant}/flow-groups/create', [FlowGroupController::class, 'create'])->name('console.flow-groups.create');
        Route::get('assistant/{assistant}/flow-groups/{record}/edit', [FlowGroupController::class, 'edit'])->name('console.flow-groups.edit');
        Route::post('assistant/{assistant}/flow-groups', [FlowGroupController::class, 'store'])->name('console.flow-groups.store');
        // A group made from a flow's form, without leaving it.
        Route::post('assistant/{assistant}/flow-groups/inline', [FlowGroupController::class, 'storeInline'])->name('console.flow-groups.store-inline');
        Route::delete('assistant/{assistant}/flow-groups', [FlowGroupController::class, 'destroyMany'])->name('console.flow-groups.destroy-many');
        Route::put('assistant/{assistant}/flow-groups/{record}', [FlowGroupController::class, 'update'])->name('console.flow-groups.update');
        Route::delete('assistant/{assistant}/flow-groups/{record}', [FlowGroupController::class, 'destroy'])->name('console.flow-groups.destroy');

        Route::get('assistant/{assistant}/flows', [FlowController::class, 'index'])->name('console.flows.index');
        Route::get('assistant/{assistant}/flows/create', [FlowController::class, 'create'])->name('console.flows.create');
        Route::get('assistant/{assistant}/flows/{record}/edit', [FlowController::class, 'edit'])->name('console.flows.edit');
        Route::post('assistant/{assistant}/flows', [FlowController::class, 'store'])->name('console.flows.store');
        Route::delete('assistant/{assistant}/flows', [FlowController::class, 'destroyMany'])->name('console.flows.destroy-many');
        Route::put('assistant/{assistant}/flows/{record}', [FlowController::class, 'update'])->name('console.flows.update');
        // The state is sent (`active`), not toggled, so a repeated request changes nothing.
        Route::patch('assistant/{assistant}/flows/{record}/active', [FlowController::class, 'updateActivity'])->name('console.flows.activity');
        Route::delete('assistant/{assistant}/flows/{record}', [FlowController::class, 'destroy'])->name('console.flows.destroy');

        // Broadcasts reach real people. `send` carries the revision the person confirmed and starts a draft at most once;
        // `cancel` and `delete` are conditional on the status; `reach` is a read, throttled because each call counts in the database.
        Route::get('assistant/{assistant}/broadcasts', [BroadcastController::class, 'index'])->name('console.broadcasts.index');
        Route::get('assistant/{assistant}/broadcasts/create', [BroadcastController::class, 'create'])->name('console.broadcasts.create');
        Route::get('assistant/{assistant}/broadcasts/reach', [BroadcastController::class, 'reach'])->middleware('throttle:30,1')->name('console.broadcasts.reach');
        Route::get('assistant/{assistant}/broadcasts/{record}/edit', [BroadcastController::class, 'edit'])->name('console.broadcasts.edit');
        Route::post('assistant/{assistant}/broadcasts', [BroadcastController::class, 'store'])->name('console.broadcasts.store');
        Route::put('assistant/{assistant}/broadcasts/{record}', [BroadcastController::class, 'update'])->name('console.broadcasts.update');
        Route::post('assistant/{assistant}/broadcasts/{record}/send', [BroadcastController::class, 'send'])->name('console.broadcasts.send');
        Route::post('assistant/{assistant}/broadcasts/{record}/cancel', [BroadcastController::class, 'cancel'])->name('console.broadcasts.cancel');
        Route::delete('assistant/{assistant}/broadcasts/{record}', [BroadcastController::class, 'destroy'])->name('console.broadcasts.destroy');

        // Conversations: the inbox reaches real people. `reply` carries the id of the operator's submission and sends it at
        // most once; the status is sent, not toggled; a takeover only takes a thread the bot answers.
        Route::get('assistant/{assistant}/conversations', [ConversationController::class, 'index'])->name('console.conversations.index');
        Route::get('assistant/{assistant}/conversations/{record}', [ConversationController::class, 'show'])->name('console.conversations.show');
        Route::post('assistant/{assistant}/conversations/{record}/reply', [ConversationController::class, 'reply'])->middleware('throttle:30,1')->name('console.conversations.reply');
        Route::post('assistant/{assistant}/conversations/{record}/takeover', [ConversationController::class, 'takeOver'])->name('console.conversations.takeover');
        Route::post('assistant/{assistant}/conversations/{record}/return-to-bot', [ConversationController::class, 'returnToBot'])->name('console.conversations.return-to-bot');
        Route::put('assistant/{assistant}/conversations/{record}/status', [ConversationController::class, 'updateStatus'])->name('console.conversations.status');

        // Flow sessions and the flow log: read-only, kept current live or by polling. Every log read is bounded in time.
        Route::get('assistant/{assistant}/flow-sessions', [FlowSessionController::class, 'index'])->name('console.flow-sessions.index');
        Route::get('assistant/{assistant}/flow-sessions/{record}', [FlowSessionController::class, 'show'])->name('console.flow-sessions.show');
        Route::get('assistant/{assistant}/flow-logs', [FlowLogController::class, 'index'])->name('console.flow-logs.index');
        Route::get('assistant/{assistant}/flow-logs/{record}', [FlowLogController::class, 'show'])->name('console.flow-logs.show');
        // Assistant settings: one form saved as a whole. A flow made from inside it is saved with the form and opens in the builder.
        Route::get('assistant/{assistant}/settings', [AssistantSettingsController::class, 'edit'])->name('console.settings.edit');
        Route::put('assistant/{assistant}/settings', [AssistantSettingsController::class, 'update'])->name('console.settings.update');
        Route::post('assistant/{assistant}/settings/flows', [AssistantSettingsController::class, 'storeFlow'])->name('console.settings.store-flow');
        // Translations: the assistant's own overrides of the system catalog, over the tenant's (shown as inherited).
        Route::get('assistant/{assistant}/translations', [TranslationController::class, 'index'])->name('console.translations.index');
        Route::put('assistant/{assistant}/translations/{key}', [TranslationController::class, 'update'])->name('console.translations.update');
        Route::delete('assistant/{assistant}/translations/{key}', [TranslationController::class, 'reset'])->name('console.translations.reset');
    });
});

// Solutions (extension-contract): the routes they declared, under `apps/<id>/` and `assistant/{assistant}/apps/<id>/`,
// named `solutions.<id>.*`, behind the `solution` stacks. The registrar sets the panel domain itself.
app(SolutionRoutes::class)->register();
