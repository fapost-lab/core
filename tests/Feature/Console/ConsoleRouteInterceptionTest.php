<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Http\Controllers\Admin\AssistantChannelController as AdminAssistantChannelController;
use App\Http\Controllers\Admin\AssistantController as AdminAssistantController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\MediaController as AdminMediaController;
use App\Http\Controllers\Admin\MediaFolderController as AdminMediaFolderController;
use App\Http\Controllers\Admin\PlatformSettingsController as AdminPlatformSettingsController;
use App\Http\Controllers\Admin\RoleController as AdminRoleController;
use App\Http\Controllers\Admin\SearchController as AdminSearchController;
use App\Http\Controllers\Admin\SolutionController as AdminSolutionController;
use App\Http\Controllers\Admin\SupportAccessController as AdminSupportAccessController;
use App\Http\Controllers\Admin\TenantSettingsController as AdminTenantSettingsController;
use App\Http\Controllers\Admin\TranslationController as AdminTranslationController;
use App\Http\Controllers\Admin\UsageController as AdminUsageController;
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
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

/**
 * The console's route table: each name the Filament panels used (and the console kept) with the action and URI
 * that answer it, and the console's own write routes. Renaming a route or changing its URI fails here first.
 */
final class ConsoleRouteInterceptionTest extends FeatureTestCase
{
    /**
     * @return array<string, array{0: string, 1: class-string, 2: string, 3: string}>
     */
    public static function interceptedRoutes(): array
    {
        return [
            'admin login'         => ['console.auth.login', LoginController::class, 'show', 'admin/login'],
            'assistant login'     => ['console.auth.assistant-login', LoginController::class, 'assistant', 'assistant/login'],
            'assistant dashboard' => ['console.dashboard', DashboardController::class, '', 'assistant/{assistant}/dashboard'],
            // Addresses that only send on, kept for bookmarks.
            'assistant landing'     => ['console.entry', AssistantHomeController::class, 'index', 'assistant'],
            'assistant home'        => ['console.home', AssistantHomeController::class, 'show', 'assistant/{assistant}'],
            'channels list'         => ['console.channels.index', ChannelController::class, 'index', 'assistant/{assistant}/channels'],
            'channels create'       => ['console.channels.create', ChannelController::class, 'create', 'assistant/{assistant}/channels/create'],
            'channels edit'         => ['console.channels.edit', ChannelController::class, 'edit', 'assistant/{assistant}/channels/{record}/edit'],
            'contacts list'         => ['console.contacts.index', ContactController::class, 'index', 'assistant/{assistant}/contacts'],
            'contacts view'         => ['console.contacts.show', ContactController::class, 'show', 'assistant/{assistant}/contacts/{record}'],
            'contact groups list'   => ['console.contact-groups.index', ContactGroupController::class, 'index', 'assistant/{assistant}/contact-groups'],
            'contact groups create' => ['console.contact-groups.create', ContactGroupController::class, 'create', 'assistant/{assistant}/contact-groups/create'],
            'contact groups edit'   => ['console.contact-groups.edit', ContactGroupController::class, 'edit', 'assistant/{assistant}/contact-groups/{record}/edit'],
            'segments list'         => ['console.contact-segments.index', ContactSegmentController::class, 'index', 'assistant/{assistant}/contact-segments'],
            'segments create'       => ['console.contact-segments.create', ContactSegmentController::class, 'create', 'assistant/{assistant}/contact-segments/create'],
            'segments edit'         => ['console.contact-segments.edit', ContactSegmentController::class, 'edit', 'assistant/{assistant}/contact-segments/{record}/edit'],
            'flow groups list'      => ['console.flow-groups.index', FlowGroupController::class, 'index', 'assistant/{assistant}/flow-groups'],
            'flow groups create'    => ['console.flow-groups.create', FlowGroupController::class, 'create', 'assistant/{assistant}/flow-groups/create'],
            'flow groups edit'      => ['console.flow-groups.edit', FlowGroupController::class, 'edit', 'assistant/{assistant}/flow-groups/{record}/edit'],
            'flows list'            => ['console.flows.index', FlowController::class, 'index', 'assistant/{assistant}/flows'],
            'flows create'          => ['console.flows.create', FlowController::class, 'create', 'assistant/{assistant}/flows/create'],
            'flows edit'            => ['console.flows.edit', FlowController::class, 'edit', 'assistant/{assistant}/flows/{record}/edit'],
            'broadcasts list'       => ['console.broadcasts.index', BroadcastController::class, 'index', 'assistant/{assistant}/broadcasts'],
            'broadcasts create'     => ['console.broadcasts.create', BroadcastController::class, 'create', 'assistant/{assistant}/broadcasts/create'],
            'broadcasts edit'       => ['console.broadcasts.edit', BroadcastController::class, 'edit', 'assistant/{assistant}/broadcasts/{record}/edit'],
            'flow sessions list'    => ['console.flow-sessions.index', FlowSessionController::class, 'index', 'assistant/{assistant}/flow-sessions'],
            'flow sessions view'    => ['console.flow-sessions.show', FlowSessionController::class, 'show', 'assistant/{assistant}/flow-sessions/{record}'],
            'flow logs list'        => ['console.flow-logs.index', FlowLogController::class, 'index', 'assistant/{assistant}/flow-logs'],
            'flow logs view'        => ['console.flow-logs.show', FlowLogController::class, 'show', 'assistant/{assistant}/flow-logs/{record}'],
            // Conversations.
            'conversations list' => ['console.conversations.index', ConversationController::class, 'index', 'assistant/{assistant}/conversations'],
            'conversations view' => ['console.conversations.show', ConversationController::class, 'show', 'assistant/{assistant}/conversations/{record}'],
            // Assistant settings.
            'assistant settings' => ['console.settings.edit', AssistantSettingsController::class, 'edit', 'assistant/{assistant}/settings'],
            // Translations: both panels.
            'admin translations'     => ['console.admin.translations.index', AdminTranslationController::class, 'index', 'admin/translations'],
            'assistant translations' => ['console.translations.index', TranslationController::class, 'index', 'assistant/{assistant}/translations'],
            // Staff users and roles.
            'users list'   => ['console.admin.users.index', AdminUserController::class, 'index', 'admin/users'],
            'users create' => ['console.admin.users.create', AdminUserController::class, 'create', 'admin/users/create'],
            'users edit'   => ['console.admin.users.edit', AdminUserController::class, 'edit', 'admin/users/{record}/edit'],
            'roles list'   => ['console.admin.roles.index', AdminRoleController::class, 'index', 'admin/roles'],
            'roles create' => ['console.admin.roles.create', AdminRoleController::class, 'create', 'admin/roles/create'],
            'roles edit'   => ['console.admin.roles.edit', AdminRoleController::class, 'edit', 'admin/roles/{record}/edit'],
            // Admin assistants.
            'admin assistants list'   => ['console.admin.assistants.index', AdminAssistantController::class, 'index', 'admin/assistants'],
            'admin assistants create' => ['console.admin.assistants.create', AdminAssistantController::class, 'create', 'admin/assistants/create'],
            'admin assistants view'   => ['console.admin.assistants.show', AdminAssistantController::class, 'show', 'admin/assistants/{record}'],
            'admin assistants edit'   => ['console.admin.assistants.edit', AdminAssistantController::class, 'edit', 'admin/assistants/{record}/edit'],
            // Admin dashboard.
            'admin dashboard' => ['console.admin.dashboard', AdminDashboardController::class, '', 'admin'],

            'admin media list'      => ['console.admin.media.index', AdminMediaController::class, 'index', 'admin/media'],
            'admin media view'      => ['console.admin.media.show', AdminMediaController::class, 'show', 'admin/media/{record}'],
            'admin tenant settings' => ['console.admin.tenant-settings.edit', AdminTenantSettingsController::class, 'edit', 'admin/tenant-settings'],
        ];
    }

    /**
     * The writes Filament had no routes for. `store-inline`, `activity` and `rotate-webhook` are not the camel-cased route
     * name, so the action is spelled out.
     *
     * @return array<string, array{0: string, 1: class-string, 2: string, 3: string, 4: string}>
     */
    public static function newRoutes(): array
    {
        return [
            'channel store'            => ['console.channels.store', ChannelController::class, 'store', 'POST', 'assistant/{assistant}/channels'],
            'channel update'           => ['console.channels.update', ChannelController::class, 'update', 'PUT', 'assistant/{assistant}/channels/{record}'],
            'channel rotate webhook'   => ['console.channels.rotate-webhook', ChannelController::class, 'rotateWebhook', 'POST', 'assistant/{assistant}/channels/{record}/rotate-webhook'],
            'channel register webhook' => ['console.channels.register-webhook', ChannelController::class, 'registerWebhook', 'POST', 'assistant/{assistant}/channels/{record}/register-webhook'],
            'channel destroy'          => ['console.channels.destroy', ChannelController::class, 'destroy', 'DELETE', 'assistant/{assistant}/channels/{record}'],
            'contact tags'             => ['console.contacts.tags', ContactController::class, 'updateTags', 'PUT', 'assistant/{assistant}/contacts/{record}/tags'],
            'contact groups'           => ['console.contacts.groups', ContactController::class, 'updateGroups', 'PUT', 'assistant/{assistant}/contacts/{record}/groups'],
            'flow group store'         => ['console.flow-groups.store', FlowGroupController::class, 'store', 'POST', 'assistant/{assistant}/flow-groups'],
            'flow group store inline'  => ['console.flow-groups.store-inline', FlowGroupController::class, 'storeInline', 'POST', 'assistant/{assistant}/flow-groups/inline'],
            'flow group update'        => ['console.flow-groups.update', FlowGroupController::class, 'update', 'PUT', 'assistant/{assistant}/flow-groups/{record}'],
            'flow group destroy'       => ['console.flow-groups.destroy', FlowGroupController::class, 'destroy', 'DELETE', 'assistant/{assistant}/flow-groups/{record}'],
            'flow group destroy many'  => ['console.flow-groups.destroy-many', FlowGroupController::class, 'destroyMany', 'DELETE', 'assistant/{assistant}/flow-groups'],
            'flow store'               => ['console.flows.store', FlowController::class, 'store', 'POST', 'assistant/{assistant}/flows'],
            'flow update'              => ['console.flows.update', FlowController::class, 'update', 'PUT', 'assistant/{assistant}/flows/{record}'],
            'flow activity'            => ['console.flows.activity', FlowController::class, 'updateActivity', 'PATCH', 'assistant/{assistant}/flows/{record}/active'],
            'flow destroy'             => ['console.flows.destroy', FlowController::class, 'destroy', 'DELETE', 'assistant/{assistant}/flows/{record}'],
            'flow destroy many'        => ['console.flows.destroy-many', FlowController::class, 'destroyMany', 'DELETE', 'assistant/{assistant}/flows'],
            'segment store'            => ['console.contact-segments.store', ContactSegmentController::class, 'store', 'POST', 'assistant/{assistant}/contact-segments'],
            'segment update'           => ['console.contact-segments.update', ContactSegmentController::class, 'update', 'PUT', 'assistant/{assistant}/contact-segments/{record}'],
            'segment destroy'          => ['console.contact-segments.destroy', ContactSegmentController::class, 'destroy', 'DELETE', 'assistant/{assistant}/contact-segments/{record}'],
            'segment count'            => ['console.contact-segments.count', ContactSegmentController::class, 'refreshCount', 'POST', 'assistant/{assistant}/contact-segments/{record}/count'],
            'broadcast reach'          => ['console.broadcasts.reach', BroadcastController::class, 'reach', 'GET', 'assistant/{assistant}/broadcasts/reach'],
            'broadcast store'          => ['console.broadcasts.store', BroadcastController::class, 'store', 'POST', 'assistant/{assistant}/broadcasts'],
            'broadcast update'         => ['console.broadcasts.update', BroadcastController::class, 'update', 'PUT', 'assistant/{assistant}/broadcasts/{record}'],
            'broadcast send'           => ['console.broadcasts.send', BroadcastController::class, 'send', 'POST', 'assistant/{assistant}/broadcasts/{record}/send'],
            'broadcast cancel'         => ['console.broadcasts.cancel', BroadcastController::class, 'cancel', 'POST', 'assistant/{assistant}/broadcasts/{record}/cancel'],
            'broadcast destroy'        => ['console.broadcasts.destroy', BroadcastController::class, 'destroy', 'DELETE', 'assistant/{assistant}/broadcasts/{record}'],
            // Conversations.
            'conversation reply'    => ['console.conversations.reply', ConversationController::class, 'reply', 'POST', 'assistant/{assistant}/conversations/{record}/reply'],
            'conversation takeover' => ['console.conversations.takeover', ConversationController::class, 'takeOver', 'POST', 'assistant/{assistant}/conversations/{record}/takeover'],
            'conversation finish'   => ['console.conversations.finish', ConversationController::class, 'finish', 'POST', 'assistant/{assistant}/conversations/{record}/finish'],
            'conversation transfer' => ['console.conversations.transfer', ConversationController::class, 'transfer', 'POST', 'assistant/{assistant}/conversations/{record}/transfer'],
            'conversation seize'    => ['console.conversations.seize', ConversationController::class, 'seize', 'POST', 'assistant/{assistant}/conversations/{record}/seize'],
            // Assistant settings.
            'settings update'     => ['console.settings.update', AssistantSettingsController::class, 'update', 'PUT', 'assistant/{assistant}/settings'],
            'settings store flow' => ['console.settings.store-flow', AssistantSettingsController::class, 'storeFlow', 'POST', 'assistant/{assistant}/settings/flows'],
            // Translations: both panels.
            'translation update'       => ['console.translations.update', TranslationController::class, 'update', 'PUT', 'assistant/{assistant}/translations/{key}'],
            'translation reset'        => ['console.translations.reset', TranslationController::class, 'reset', 'DELETE', 'assistant/{assistant}/translations/{key}'],
            'admin translation update' => ['console.admin.translations.update', AdminTranslationController::class, 'update', 'PUT', 'admin/translations/{key}'],
            'admin translation reset'  => ['console.admin.translations.reset', AdminTranslationController::class, 'reset', 'DELETE', 'admin/translations/{key}'],
            // Staff users and roles.
            'user store'             => ['console.admin.users.store', AdminUserController::class, 'store', 'POST', 'admin/users'],
            'user update'            => ['console.admin.users.update', AdminUserController::class, 'update', 'PUT', 'admin/users/{record}'],
            'user activity'          => ['console.admin.users.activity', AdminUserController::class, 'updateActivity', 'PATCH', 'admin/users/{record}/active'],
            'user resend activation' => ['console.admin.users.resend-activation', AdminUserController::class, 'resendActivation', 'POST', 'admin/users/{record}/resend-activation'],
            'user destroy'           => ['console.admin.users.destroy', AdminUserController::class, 'destroy', 'DELETE', 'admin/users/{record}'],
            'user destroy many'      => ['console.admin.users.destroy-many', AdminUserController::class, 'destroyMany', 'DELETE', 'admin/users'],
            'role store'             => ['console.admin.roles.store', AdminRoleController::class, 'store', 'POST', 'admin/roles'],
            'role update'            => ['console.admin.roles.update', AdminRoleController::class, 'update', 'PUT', 'admin/roles/{record}'],
            'role destroy'           => ['console.admin.roles.destroy', AdminRoleController::class, 'destroy', 'DELETE', 'admin/roles/{record}'],
            'role destroy many'      => ['console.admin.roles.destroy-many', AdminRoleController::class, 'destroyMany', 'DELETE', 'admin/roles'],
            // Admin assistants and their channels.
            'admin assistant store'            => ['console.admin.assistants.store', AdminAssistantController::class, 'store', 'POST', 'admin/assistants'],
            'admin assistant update'           => ['console.admin.assistants.update', AdminAssistantController::class, 'update', 'PUT', 'admin/assistants/{record}'],
            'admin assistant destroy'          => ['console.admin.assistants.destroy', AdminAssistantController::class, 'destroy', 'DELETE', 'admin/assistants/{record}'],
            'admin assistant channel edit'     => ['console.admin.assistants.channels.edit', AdminAssistantChannelController::class, 'edit', 'GET', 'admin/assistants/{record}/channels/{channel}/edit'],
            'admin assistant channel update'   => ['console.admin.assistants.channels.update', AdminAssistantChannelController::class, 'update', 'PUT', 'admin/assistants/{record}/channels/{channel}'],
            'admin assistant channel rotate'   => ['console.admin.assistants.channels.rotate-webhook', AdminAssistantChannelController::class, 'rotateWebhook', 'POST', 'admin/assistants/{record}/channels/{channel}/rotate-webhook'],
            'admin assistant channel register' => ['console.admin.assistants.channels.register-webhook', AdminAssistantChannelController::class, 'registerWebhook', 'POST', 'admin/assistants/{record}/channels/{channel}/register-webhook'],
            'admin assistant channel destroy'  => ['console.admin.assistants.channels.destroy', AdminAssistantChannelController::class, 'destroy', 'DELETE', 'admin/assistants/{record}/channels/{channel}'],

            'admin media upload'           => ['console.admin.media.upload', AdminMediaController::class, 'upload', 'POST', 'admin/media'],
            'admin media move'             => ['console.admin.media.move', AdminMediaController::class, 'move', 'PUT', 'admin/media/move'],
            'admin media destroy many'     => ['console.admin.media.destroy-many', AdminMediaController::class, 'destroyMany', 'DELETE', 'admin/media'],
            'admin media update'           => ['console.admin.media.update', AdminMediaController::class, 'update', 'PATCH', 'admin/media/{record}'],
            'admin media destroy'          => ['console.admin.media.destroy', AdminMediaController::class, 'destroy', 'DELETE', 'admin/media/{record}'],
            'admin media restore'          => ['console.admin.media.restore', AdminMediaController::class, 'restore', 'POST', 'admin/media/{record}/restore'],
            'admin media force'            => ['console.admin.media.force', AdminMediaController::class, 'forceDestroy', 'DELETE', 'admin/media/{record}/force'],
            'admin media folder store'     => ['console.admin.media.folders.store', AdminMediaFolderController::class, 'store', 'POST', 'admin/media/folders'],
            'admin media folder update'    => ['console.admin.media.folders.update', AdminMediaFolderController::class, 'update', 'PUT', 'admin/media/folders/{folder}'],
            'admin media folder destroy'   => ['console.admin.media.folders.destroy', AdminMediaFolderController::class, 'destroy', 'DELETE', 'admin/media/folders/{folder}'],
            'admin tenant settings update' => ['console.admin.tenant-settings.update', AdminTenantSettingsController::class, 'update', 'PUT', 'admin/tenant-settings'],
            // Platform settings: a new screen, never a Filament one.
            'admin platform settings'        => ['console.admin.platform-settings.edit', AdminPlatformSettingsController::class, 'edit', 'GET', 'admin/platform-settings'],
            'admin platform settings update' => ['console.admin.platform-settings.update', AdminPlatformSettingsController::class, 'update', 'PUT', 'admin/platform-settings'],
            // Support access log: never a Filament screen.
            'admin support access' => ['console.admin.support-access.index', AdminSupportAccessController::class, 'index', 'GET', 'admin/support-access'],
            // Usage and limits: a new screen, never a Filament one.
            'admin usage' => ['console.admin.usage.index', AdminUsageController::class, 'index', 'GET', 'admin/usage'],
            // Solutions: a new screen, never a Filament one.
            'admin solutions'            => ['console.admin.solutions.index', AdminSolutionController::class, 'index', 'GET', 'admin/solutions'],
            'admin solutions activate'   => ['console.admin.solutions.activate', AdminSolutionController::class, 'activate', 'POST', 'admin/solutions/{solution}/activate'],
            'admin solutions deactivate' => ['console.admin.solutions.deactivate', AdminSolutionController::class, 'deactivate', 'POST', 'admin/solutions/{solution}/deactivate'],
            // Admin search palette: Filament's global search had no route.
            'admin search' => ['console.admin.search', AdminSearchController::class, '', 'GET', 'admin/search'],
            // App switcher: the user's own rail, in both panels.
            'application rail' => ['console.applications.rail.update', ApplicationRailController::class, '', 'PUT', 'console/applications/rail'],
            // Notification bell: the user's own notifications, in both panels.
            'notifications index'    => ['console.notifications.index', NotificationController::class, 'index', 'GET', 'console/notifications'],
            'notifications count'    => ['console.notifications.count', NotificationController::class, 'count', 'GET', 'console/notifications/count'],
            'notification read'      => ['console.notifications.read', NotificationController::class, 'read', 'POST', 'console/notifications/{notification}/read'],
            'notifications read all' => ['console.notifications.read-all', NotificationController::class, 'readAll', 'POST', 'console/notifications/read'],
        ];
    }

    #[DataProvider('interceptedRoutes')]
    public function test_the_console_answers_the_intercepted_name(string $name, string $controller, string $method, string $uri): void
    {
        $route = $this->route($name);

        $this->assertSame('' === $method ? $controller : $controller . '@' . $method, $route->getActionName(), $name);
        $this->assertSame($uri, $route->uri(), $name);
        $this->assertSame(['GET', 'HEAD'], $route->methods(), $name);
    }

    #[DataProvider('interceptedRoutes')]
    public function test_exactly_one_route_remains_for_the_key(string $name, string $controller, string $method, string $uri): void
    {
        $same = array_filter(
            Route::getRoutes()->getRoutes(),
            static fn (RoutingRoute $route): bool => $route->uri() === $uri && in_array('GET', $route->methods(), true),
        );

        $this->assertCount(1, $same, "{$uri} is answered by more than one route.");
    }

    public function test_the_new_routes_exist(): void
    {
        $this->assertSame(LoginController::class . '@store', $this->route('console.auth.login.attempt')->getActionName());
        $this->assertSame(LogoutController::class, $this->route('console.auth.logout')->getActionName());
        $this->assertSame(LocaleController::class, $this->route('console.locale.update')->getActionName());

        foreach ([
            'store'        => ['POST', 'assistant/{assistant}/contact-groups'],
            'update'       => ['PUT', 'assistant/{assistant}/contact-groups/{record}'],
            'destroy'      => ['DELETE', 'assistant/{assistant}/contact-groups/{record}'],
            'destroy-many' => ['DELETE', 'assistant/{assistant}/contact-groups'],
        ] as $action => [$verb, $uri]) {
            $route = $this->route("console.contact-groups.{$action}");

            $this->assertSame(ContactGroupController::class . '@' . lcfirst(str_replace('-', '', ucwords($action, '-'))), $route->getActionName());
            $this->assertContains($verb, $route->methods());
            $this->assertSame($uri, $route->uri());
        }
    }

    #[DataProvider('newRoutes')]
    public function test_the_new_write_routes_exist(string $name, string $controller, string $method, string $verb, string $uri): void
    {
        $route = $this->route($name);

        $this->assertSame('' === $method ? $controller : $controller . '@' . $method, $route->getActionName(), $name);
        $this->assertContains($verb, $route->methods(), $name);
        $this->assertSame($uri, $route->uri(), $name);
    }

    public function test_the_builder_flow_page_has_a_name(): void
    {
        $this->assertSame('builder/flows/{flow}', $this->route('builder.flows.show')->uri());
        $this->assertSame('/builder/flows/abc', route('builder.flows.show', ['flow' => 'abc'], false));
    }

    public function test_route_names_are_unique(): void
    {
        $names = array_values(array_filter(array_map(
            static fn (RoutingRoute $route): ?string => $route->getName(),
            Route::getRoutes()->getRoutes(),
        )));

        $this->assertSame([], array_values(array_unique(array_diff_assoc($names, array_unique($names)))), 'Duplicate route names.');
    }

    public function test_the_route_table_compiles_as_route_cache_would_compile_it(): void
    {
        $compiled = Route::getRoutes()->compile();

        $this->assertNotEmpty($compiled['attributes']);
        $this->assertArrayHasKey('console.auth.login', $compiled['attributes']);
    }

    private function route(string $name): RoutingRoute
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertNotNull($route, "Route [{$name}] is not registered.");

        return $route;
    }
}
