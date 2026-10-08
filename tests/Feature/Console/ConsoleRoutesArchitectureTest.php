<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Http\Middleware\EnsureCanAccessPanel;
use App\Http\Middleware\ForgetInvalidAuthenticatedSession;
use App\Http\Middleware\RefuseWritesWhenTenantStopped;
use App\Http\Middleware\ResolveCurrentAssistant;
use App\Http\Middleware\TenancyMiddleware;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

/**
 * Rules for the routes of the Inertia console, checked on the resolved route table (the middleware groups
 * expanded and sorted as the router would run them), with the switch on:
 *
 * - every console route under `assistant/{tenant}/` resolves the current assistant;
 * - every route under `admin/`, `assistant/` or `console/` that is not Filament's enters the tenant before the
 *   cookies and the session;
 * - the `admin` stack keeps the order its checks need.
 *
 * Filament's own routes are left out where they differ (they carry their own stack, covered by
 * {@see \Tests\Feature\Tenancy\TenantMiddlewareOrderTest}). Each rule is proved on a violator, so a refactor of the
 * route table cannot turn them into passes that check nothing.
 */
final class ConsoleRoutesArchitectureTest extends InertiaConsoleTestCase
{
    public function test_every_console_assistant_route_resolves_the_current_assistant(): void
    {
        $this->assertSame([], $this->assistantRoutesWithoutAssistant());
    }

    public function test_the_assistant_rule_fires_on_a_violator(): void
    {
        Route::middleware('admin')->get('assistant/{tenant}/_violator', fn (): string => 'ok');

        $this->assertSame(['assistant/{tenant}/_violator'], $this->assistantRoutesWithoutAssistant());
    }

    public function test_every_console_route_enters_the_tenant_before_the_session(): void
    {
        $this->assertSame([], $this->routesWithoutEarlyTenant());
    }

    public function test_the_tenant_rule_fires_on_a_violator(): void
    {
        Route::middleware('web')->get('admin/_violator', fn (): string => 'ok');

        $this->assertSame(['admin/_violator'], $this->routesWithoutEarlyTenant());
    }

    public function test_the_rules_see_filament_and_console_routes_apart(): void
    {
        $filament = $console = 0;

        foreach (Route::getRoutes() as $route) {
            $this->isFilament($route) ? $filament++ : (($this->isConsolePath($route)) ? $console++ : null);
        }

        $this->assertGreaterThan(2, $filament, 'Filament routes were not recognised.');
        $this->assertGreaterThan(2, $console, 'Console routes were not found.');
    }

    public function test_the_admin_stack_checks_in_the_order_they_need(): void
    {
        Route::middleware('admin')->get('admin/_order', fn (): string => 'ok');
        $stack = $this->stack($this->routeByUri('admin/_order'));

        $this->assertBefore($stack, TenancyMiddleware::class, EncryptCookies::class);
        $this->assertBefore($stack, TenancyMiddleware::class, StartSession::class);
        $this->assertBefore($stack, StartSession::class, ForgetInvalidAuthenticatedSession::class);
        $this->assertBefore($stack, ForgetInvalidAuthenticatedSession::class, Authenticate::class);
        $this->assertBefore($stack, Authenticate::class, EnsureCanAccessPanel::class);
        $this->assertBefore($stack, EnsureCanAccessPanel::class, RefuseWritesWhenTenantStopped::class);
    }

    public function test_the_console_stack_resolves_the_assistant_after_authentication(): void
    {
        Route::middleware('console')->get('assistant/{tenant}/_order', fn (): string => 'ok');
        $stack = $this->stack($this->routeByUri('assistant/{tenant}/_order'));

        $this->assertContains(ResolveCurrentAssistant::class . ':tenant', $stack);
        $this->assertBefore($stack, Authenticate::class, ResolveCurrentAssistant::class . ':tenant');
        $this->assertBefore($stack, EnsureCanAccessPanel::class, ResolveCurrentAssistant::class . ':tenant');
    }

    /**
     * @return list<string>
     */
    private function assistantRoutesWithoutAssistant(): array
    {
        $violations = [];

        foreach (Route::getRoutes() as $route) {
            if ($this->isFilament($route) || ! str_starts_with($route->uri(), 'assistant/{tenant}/')) {
                continue;
            }

            if (! in_array(ResolveCurrentAssistant::class . ':tenant', $this->stack($route), true)) {
                $violations[] = $route->uri();
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function routesWithoutEarlyTenant(): array
    {
        $violations = [];

        foreach (Route::getRoutes() as $route) {
            if ($this->isFilament($route) || ! $this->isConsolePath($route)) {
                continue;
            }

            $stack  = $this->stack($route);
            $tenant = array_search(TenancyMiddleware::class, $stack, true);

            if (! is_int($tenant)
                || $tenant > (int) array_search(EncryptCookies::class, $stack, true)
                || $tenant > (int) array_search(StartSession::class, $stack, true)) {
                $violations[] = $route->uri();
            }
        }

        return $violations;
    }

    private function isConsolePath(RoutingRoute $route): bool
    {
        $uri = $route->uri();

        return 'admin' === $uri
            || str_starts_with($uri, 'admin/')
            || str_starts_with($uri, 'assistant/')
            || str_starts_with($uri, 'console/');
    }

    /**
     * A Filament route carries the `panel:<id>` middleware that sets its panel up.
     */
    private function isFilament(RoutingRoute $route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && (str_starts_with($middleware, 'panel:') || str_contains($middleware, 'SetUpPanel'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function stack(RoutingRoute $route): array
    {
        return array_values(array_filter(
            $this->app['router']->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware()),
            'is_string',
        ));
    }

    private function routeByUri(string $uri): RoutingRoute
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri) {
                return $route;
            }
        }

        $this->fail("No route [{$uri}].");
    }

    /**
     * @param  list<string>  $stack
     */
    private function assertBefore(array $stack, string $first, string $second): void
    {
        $a = array_search($first, $stack, true);
        $b = array_search($second, $stack, true);

        $this->assertIsInt($a, "{$first} is missing.");
        $this->assertIsInt($b, "{$second} is missing.");
        $this->assertLessThan($b, $a, "{$first} must run before {$second}.");
    }
}
