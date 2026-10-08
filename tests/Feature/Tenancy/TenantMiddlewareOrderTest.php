<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\TenancyMiddleware;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureTestCase;

/**
 * Sessions and users live in the tenant schema, so the tenant has to be active before
 * the session is started. Router middleware priority is what decides that order.
 */
final class TenantMiddlewareOrderTest extends FeatureTestCase
{
    public function test_panel_logins_enter_the_tenant_before_the_session_starts(): void
    {
        foreach (['filament.admin.auth.login', 'filament.assistant.auth.login'] as $name) {
            $stack = $this->stackFor($name);

            $tenant = array_search(TenancyMiddleware::class, $stack, true);
            $this->assertIsInt($tenant, "{$name} has no TenancyMiddleware.");
            $this->assertLessThan(array_search(EncryptCookies::class, $stack, true), $tenant, "{$name}: tenant after cookies.");
            $this->assertLessThan(array_search(StartSession::class, $stack, true), $tenant, "{$name}: tenant after session.");
        }
    }

    public function test_every_panel_route_enters_the_tenant_before_the_session_starts(): void
    {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'filament.admin.') && ! str_starts_with($name, 'filament.assistant.')) {
                continue;
            }

            $stack = array_values(array_filter(
                $this->app['router']->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware()),
                'is_string',
            ));

            $tenant = array_search(TenancyMiddleware::class, $stack, true);
            $this->assertIsInt($tenant, "{$name} has no TenancyMiddleware.");
            $this->assertLessThan(array_search(EncryptCookies::class, $stack, true), $tenant, "{$name}: tenant after cookies.");
            $this->assertLessThan(array_search(StartSession::class, $stack, true), $tenant, "{$name}: tenant after session.");
            $checked++;
        }

        $this->assertGreaterThan(2, $checked, 'Panel routes were not found.');
    }

    /**
     * By address rather than by route name: whatever answers under `admin/` or `assistant/` (Filament's route or, with
     * `UI_INERTIA` on, the console's, which keeps the name but not the stack) enters the tenant first.
     * The same rule with the switch on, and proved on a violator, is in
     * {@see \Tests\Feature\Console\ConsoleRoutesArchitectureTest}.
     */
    public function test_every_route_under_the_panel_prefixes_enters_the_tenant_before_the_session_starts(): void
    {
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if ('admin' !== $uri && ! str_starts_with($uri, 'admin/') && ! str_starts_with($uri, 'assistant')) {
                continue;
            }

            $stack = array_values(array_filter(
                $this->app['router']->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware()),
                'is_string',
            ));

            $tenant = array_search(TenancyMiddleware::class, $stack, true);
            $this->assertIsInt($tenant, "{$uri} has no TenancyMiddleware.");
            $this->assertLessThan(array_search(EncryptCookies::class, $stack, true), $tenant, "{$uri}: tenant after cookies.");
            $this->assertLessThan(array_search(StartSession::class, $stack, true), $tenant, "{$uri}: tenant after session.");
            $checked++;
        }

        $this->assertGreaterThan(2, $checked, 'Panel routes were not found.');
    }

    public function test_web_group_resolves_the_tenant_before_the_session_starts(): void
    {
        Route::middleware('web')->get('/_order', fn () => 'ok')->name('order.probe');
        Route::getRoutes()->refreshNameLookups();

        $stack = $this->stackFor('order.probe');

        $this->assertSame(ResolveTenantContext::class, $stack[0]);
        $this->assertGreaterThan(0, array_search(StartSession::class, $stack, true));
    }

    public function test_builder_and_media_enter_the_tenant_before_authentication(): void
    {
        foreach (['/builder/node-types', '/media/folders'] as $uri) {
            $route = collect(Route::getRoutes())->first(fn ($r): bool => $r->uri() === mb_ltrim($uri, '/'));
            $stack = array_values(array_filter(
                $this->app['router']->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware()),
                'is_string',
            ));

            $this->assertLessThan(
                array_search(Authenticate::class, $stack, true),
                array_search(TenancyMiddleware::class, $stack, true),
                "{$uri}: tenant after auth.",
            );
        }
    }

    public function test_panel_login_works_with_database_sessions(): void
    {
        config(['session.driver' => 'database']);

        $this->get($this->panelUrl('/admin/login'))->assertOk();
    }
    /**
     * @return list<string>
     */
    private function stackFor(string $routeName): array
    {
        $route = Route::getRoutes()->getByName($routeName);
        $this->assertNotNull($route, "Route [{$routeName}] is not registered.");

        return array_values(array_filter(
            $this->app['router']->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware()),
            'is_string',
        ));
    }
}
