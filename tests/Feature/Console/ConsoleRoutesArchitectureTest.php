<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Http\Middleware\EnsureCanAccessPanel;
use App\Http\Middleware\ForgetInvalidAuthenticatedSession;
use App\Http\Middleware\RefuseWritesWhenTenantStopped;
use App\Http\Middleware\ResolveCurrentAssistant;
use App\Http\Middleware\TenancyMiddleware;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureTestCase;

/**
 * Rules for the routes of the console, checked on the resolved route table (the middleware groups expanded and
 * sorted as the router would run them):
 *
 * - every console route under `assistant/{assistant}/` resolves the current assistant;
 * - every route under `admin/`, `assistant/` or `console/` enters the tenant before the cookies and the session;
 * - the `admin` stack keeps the order its checks need.
 *
 * Each rule is proved on a violator, so a refactor of the route table cannot turn them into passes that check nothing.
 */
final class ConsoleRoutesArchitectureTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The HTTP kernel hands the middleware groups and priority to the router when it is built; nothing here sends a
        // request before the middleware is resolved, so build it.
        $this->app->make(HttpKernel::class);
    }

    public function test_every_console_assistant_route_resolves_the_current_assistant(): void
    {
        $this->assertSame([], $this->assistantRoutesWithoutAssistant());
    }

    public function test_the_assistant_rule_fires_on_a_violator(): void
    {
        Route::middleware('admin')->get('assistant/{assistant}/_violator', fn (): string => 'ok');

        $this->assertSame(['assistant/{assistant}/_violator'], $this->assistantRoutesWithoutAssistant());
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

    public function test_the_rules_find_the_console_routes(): void
    {
        $console = array_filter(Route::getRoutes()->getRoutes(), $this->isConsolePath(...));

        $this->assertGreaterThan(2, count($console), 'Console routes were not found.');
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
        Route::middleware('console')->get('assistant/{assistant}/_order', fn (): string => 'ok');
        $stack = $this->stack($this->routeByUri('assistant/{assistant}/_order'));

        $this->assertContains(ResolveCurrentAssistant::class, $stack);
        $this->assertBefore($stack, Authenticate::class, ResolveCurrentAssistant::class);
        $this->assertBefore($stack, EnsureCanAccessPanel::class, ResolveCurrentAssistant::class);
    }

    /**
     * @return list<string>
     */
    private function assistantRoutesWithoutAssistant(): array
    {
        $violations = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'assistant/{assistant}/')) {
                continue;
            }

            if (! in_array(ResolveCurrentAssistant::class, $this->stack($route), true)) {
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
            if (! $this->isConsolePath($route)) {
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
