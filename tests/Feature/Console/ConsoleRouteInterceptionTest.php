<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Http\Controllers\Console\Auth\LoginController;
use App\Http\Controllers\Console\Auth\LogoutController;
use App\Http\Controllers\Console\ChannelController;
use App\Http\Controllers\Console\ContactController;
use App\Http\Controllers\Console\ContactGroupController;
use App\Http\Controllers\Console\DashboardController;
use App\Http\Controllers\Console\FlowController;
use App\Http\Controllers\Console\FlowGroupController;
use App\Http\Controllers\Console\LocaleController;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `UI_INERTIA` on: a route declared in routes/inertia.php with the key and name of a Filament route replaces it.
 *
 * A key mistake (a different parameter name, a different domain) replaces nothing and fails silently, with
 * Filament still answering, so each intercepted name is listed here with the action that must answer it.
 * {@see ConsoleSwitchTest} holds the same names with the switch off.
 */
final class ConsoleRouteInterceptionTest extends InertiaConsoleTestCase
{
    /**
     * @return array<string, array{0: string, 1: class-string, 2: string, 3: string}>
     */
    public static function interceptedRoutes(): array
    {
        return [
            'admin login'           => ['filament.admin.auth.login', LoginController::class, 'show', 'admin/login'],
            'assistant login'       => ['filament.assistant.auth.login', LoginController::class, 'assistant', 'assistant/login'],
            'assistant dashboard'   => ['filament.assistant.pages.dashboard', DashboardController::class, '', 'assistant/{tenant}/dashboard'],
            'channels list'         => ['filament.assistant.resources.channels.index', ChannelController::class, 'index', 'assistant/{tenant}/channels'],
            'channels create'       => ['filament.assistant.resources.channels.create', ChannelController::class, 'create', 'assistant/{tenant}/channels/create'],
            'channels edit'         => ['filament.assistant.resources.channels.edit', ChannelController::class, 'edit', 'assistant/{tenant}/channels/{record}/edit'],
            'contacts list'         => ['filament.assistant.resources.contacts.index', ContactController::class, 'index', 'assistant/{tenant}/contacts'],
            'contacts view'         => ['filament.assistant.resources.contacts.view', ContactController::class, 'show', 'assistant/{tenant}/contacts/{record}'],
            'contact groups list'   => ['filament.assistant.resources.contact-groups.index', ContactGroupController::class, 'index', 'assistant/{tenant}/contact-groups'],
            'contact groups create' => ['filament.assistant.resources.contact-groups.create', ContactGroupController::class, 'create', 'assistant/{tenant}/contact-groups/create'],
            'contact groups edit'   => ['filament.assistant.resources.contact-groups.edit', ContactGroupController::class, 'edit', 'assistant/{tenant}/contact-groups/{record}/edit'],
            'flow groups list'      => ['filament.assistant.resources.flow-groups.index', FlowGroupController::class, 'index', 'assistant/{tenant}/flow-groups'],
            'flow groups create'    => ['filament.assistant.resources.flow-groups.create', FlowGroupController::class, 'create', 'assistant/{tenant}/flow-groups/create'],
            'flow groups edit'      => ['filament.assistant.resources.flow-groups.edit', FlowGroupController::class, 'edit', 'assistant/{tenant}/flow-groups/{record}/edit'],
            'flows list'            => ['filament.assistant.resources.flows.index', FlowController::class, 'index', 'assistant/{tenant}/flows'],
            'flows create'          => ['filament.assistant.resources.flows.create', FlowController::class, 'create', 'assistant/{tenant}/flows/create'],
            'flows edit'            => ['filament.assistant.resources.flows.edit', FlowController::class, 'edit', 'assistant/{tenant}/flows/{record}/edit'],
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
            'channel store'            => ['console.channels.store', ChannelController::class, 'store', 'POST', 'assistant/{tenant}/channels'],
            'channel update'           => ['console.channels.update', ChannelController::class, 'update', 'PUT', 'assistant/{tenant}/channels/{record}'],
            'channel rotate webhook'   => ['console.channels.rotate-webhook', ChannelController::class, 'rotateWebhook', 'POST', 'assistant/{tenant}/channels/{record}/rotate-webhook'],
            'channel register webhook' => ['console.channels.register-webhook', ChannelController::class, 'registerWebhook', 'POST', 'assistant/{tenant}/channels/{record}/register-webhook'],
            'channel destroy'          => ['console.channels.destroy', ChannelController::class, 'destroy', 'DELETE', 'assistant/{tenant}/channels/{record}'],
            'contact tags'             => ['console.contacts.tags', ContactController::class, 'updateTags', 'PUT', 'assistant/{tenant}/contacts/{record}/tags'],
            'contact groups'           => ['console.contacts.groups', ContactController::class, 'updateGroups', 'PUT', 'assistant/{tenant}/contacts/{record}/groups'],
            'flow group store'         => ['console.flow-groups.store', FlowGroupController::class, 'store', 'POST', 'assistant/{tenant}/flow-groups'],
            'flow group store inline'  => ['console.flow-groups.store-inline', FlowGroupController::class, 'storeInline', 'POST', 'assistant/{tenant}/flow-groups/inline'],
            'flow group update'        => ['console.flow-groups.update', FlowGroupController::class, 'update', 'PUT', 'assistant/{tenant}/flow-groups/{record}'],
            'flow group destroy'       => ['console.flow-groups.destroy', FlowGroupController::class, 'destroy', 'DELETE', 'assistant/{tenant}/flow-groups/{record}'],
            'flow group destroy many'  => ['console.flow-groups.destroy-many', FlowGroupController::class, 'destroyMany', 'DELETE', 'assistant/{tenant}/flow-groups'],
            'flow store'               => ['console.flows.store', FlowController::class, 'store', 'POST', 'assistant/{tenant}/flows'],
            'flow update'              => ['console.flows.update', FlowController::class, 'update', 'PUT', 'assistant/{tenant}/flows/{record}'],
            'flow activity'            => ['console.flows.activity', FlowController::class, 'updateActivity', 'PATCH', 'assistant/{tenant}/flows/{record}/active'],
            'flow destroy'             => ['console.flows.destroy', FlowController::class, 'destroy', 'DELETE', 'assistant/{tenant}/flows/{record}'],
            'flow destroy many'        => ['console.flows.destroy-many', FlowController::class, 'destroyMany', 'DELETE', 'assistant/{tenant}/flows'],
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
            'store'        => ['POST', 'assistant/{tenant}/contact-groups'],
            'update'       => ['PUT', 'assistant/{tenant}/contact-groups/{record}'],
            'destroy'      => ['DELETE', 'assistant/{tenant}/contact-groups/{record}'],
            'destroy-many' => ['DELETE', 'assistant/{tenant}/contact-groups'],
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

        $this->assertSame($controller . '@' . $method, $route->getActionName(), $name);
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
        $this->assertArrayHasKey('filament.admin.auth.login', $compiled['attributes']);
    }

    private function route(string $name): RoutingRoute
    {
        $route = Route::getRoutes()->getByName($name);
        $this->assertNotNull($route, "Route [{$name}] is not registered.");

        return $route;
    }
}
