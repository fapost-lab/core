<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Http\Controllers\Console\Auth\LoginController;
use App\Http\Controllers\Console\Auth\LogoutController;
use App\Http\Controllers\Console\DashboardController;
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
            'admin login'         => ['filament.admin.auth.login', LoginController::class, 'show', 'admin/login'],
            'assistant login'     => ['filament.assistant.auth.login', LoginController::class, 'assistant', 'assistant/login'],
            'assistant dashboard' => ['filament.assistant.pages.dashboard', DashboardController::class, '', 'assistant/{tenant}/dashboard'],
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
