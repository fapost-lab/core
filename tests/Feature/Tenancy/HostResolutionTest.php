<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Exceptions\TenantSlugMovedException;
use Closure;
use Fapost\Foundation\Tenancy\Contracts\TenantRenamerInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\FeatureTestCase;

/**
 * Several tenants on one deployment, each reached by its own host.
 *
 * Runs through the real HTTP stack in `host` mode with two tenants in the landlord table:
 * the seeded `main` and a second one provisioned here, with a schema of its own on PostgreSQL.
 */
final class HostResolutionTest extends FeatureTestCase
{
    private const string SECOND_SCHEMA = 'second_schema';

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.resolution' => 'host']);
        $this->base = (string) config('tenancy.base_domain');

        $this->provisionSecondTenant();

        Route::middleware('web')->get('/_probe/tenant', fn (): array => $this->probe());
        Route::middleware(['web', 'tenant'])->get('/_probe/tenant-required', fn (): array => $this->probe());
        Route::middleware('web')->any('/_probe/any', fn (): array => $this->probe());
        Route::middleware('web')->get('/_probe/platform', fn (): array => $this->probe());
    }

    public function test_each_tenant_host_runs_in_its_own_tenant_context(): void
    {
        $this->getJson("http://main.{$this->base}/_probe/tenant")
            ->assertOk()
            ->assertJsonPath('slug', 'main');

        $this->getJson("http://second.{$this->base}/_probe/tenant")
            ->assertOk()
            ->assertJsonPath('slug', 'second');
    }

    public function test_each_tenant_host_reads_its_own_schema(): void
    {
        if (! $this->onPostgres()) {
            $this->markTestSkipped('Schema-per-tenant switching needs PostgreSQL.');
        }

        $this->getJson("http://main.{$this->base}/_probe/tenant")->assertJsonPath('schema', 'main');
        $this->getJson("http://second.{$this->base}/_probe/tenant")->assertJsonPath('schema', self::SECOND_SCHEMA);
        $this->getJson("http://main.{$this->base}/_probe/tenant")->assertJsonPath('schema', 'main');
    }

    public function test_context_does_not_outlive_the_request(): void
    {
        $this->getJson("http://second.{$this->base}/_probe/tenant")->assertOk();

        $this->assertFalse($this->app->make(TenantContextInterface::class)->isResolved());
    }

    public function test_tenant_required_route_passes_through_once_the_web_group_resolved_the_tenant(): void
    {
        $this->getJson("http://second.{$this->base}/_probe/tenant-required")
            ->assertOk()
            ->assertJsonPath('slug', 'second');
    }

    public function test_base_domain_serves_platform_pages_with_no_tenant(): void
    {
        $this->get("http://{$this->base}/")->assertOk()->assertViewIs('welcome');

        $this->getJson("http://{$this->base}/_probe/platform")
            ->assertOk()
            ->assertJsonPath('slug', null);
    }

    public function test_tenant_required_routes_answer_404_on_the_base_domain(): void
    {
        $this->getJson("http://{$this->base}/_probe/tenant-required")->assertNotFound();
        $this->getJson("http://{$this->base}/builder/node-types")->assertNotFound();
        $this->getJson("http://{$this->base}/media/folders")->assertNotFound();
        $this->getJson("http://{$this->base}/tma/api/forms/1")->assertNotFound();
    }

    public function test_tenant_required_routes_reach_their_own_checks_on_a_tenant_host(): void
    {
        // A guest on a tenant host is turned away by `auth` (401), not by tenant resolution (404).
        $this->getJson("http://main.{$this->base}/builder/node-types")->assertUnauthorized();
        $this->getJson("http://main.{$this->base}/media/folders")->assertUnauthorized();
    }

    public function test_unknown_tenant_host_is_404(): void
    {
        $this->get("http://ghost.{$this->base}/_probe/tenant")->assertNotFound();
    }

    public function test_inactive_tenant_host_is_404(): void
    {
        DB::connection('landlord')->table('tenants')->where('slug', 'second')->update(['status' => 'suspended']);

        $this->get("http://second.{$this->base}/_probe/tenant")->assertNotFound();
        $this->getJson("http://main.{$this->base}/_probe/tenant")->assertOk();
    }

    public function test_pending_tenant_host_is_404(): void
    {
        DB::connection('landlord')->table('tenants')->where('slug', 'second')->update(['status' => 'pending']);

        $this->get("http://second.{$this->base}/_probe/tenant")->assertNotFound();
    }

    public function test_reserved_slug_host_is_404_even_with_a_tenant_row(): void
    {
        DB::connection('landlord')->table('tenants')->where('slug', 'second')->update(['slug' => 'admin']);

        $this->get("http://admin.{$this->base}/_probe/tenant")->assertNotFound();
    }

    public function test_default_slug_host_is_404_in_host_mode_even_with_a_tenant_row(): void
    {
        config(['tenancy.default_tenant_slug' => 'app']);
        DB::connection('landlord')->table('tenants')->where('slug', 'second')->update(['slug' => 'app']);

        $this->get("http://app.{$this->base}/_probe/tenant")->assertNotFound();
    }

    public function test_a_former_host_redirects_get_and_head_with_302_keeping_path_and_query(): void
    {
        $this->renameSecond('second-new');

        $this->get("http://second.{$this->base}/_probe/tenant?a=1&b=two")
            ->assertStatus(302)
            ->assertRedirect($this->expectedOrigin('second-new') . '/_probe/tenant?a=1&b=two');

        $this->call('HEAD', "http://second.{$this->base}/_probe/tenant")
            ->assertStatus(302);
    }

    public function test_a_former_host_answers_404_to_any_other_method(): void
    {
        $this->renameSecond('second-new');

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->call($method, "http://second.{$this->base}/_probe/any")->assertNotFound();
        }
    }

    public function test_the_former_host_is_404_once_the_redirect_period_has_ended(): void
    {
        $this->renameSecond('second-new');
        DB::connection('landlord')->table('tenant_slug_aliases')->update(['redirect_until' => now()->subMinute()]);

        $this->get("http://second.{$this->base}/_probe/tenant")->assertNotFound();
    }

    public function test_the_former_host_is_404_when_redirects_are_switched_off(): void
    {
        config(['tenancy.rename.redirect_days' => 0]);
        $this->renameSecond('second-new');

        $this->get("http://second.{$this->base}/_probe/tenant")->assertNotFound();
    }

    public function test_the_former_host_is_404_while_the_tenant_is_not_active(): void
    {
        $this->renameSecond('second-new');
        DB::connection('landlord')->table('tenants')->where('slug', 'second-new')->update(['status' => 'suspended']);

        $this->get("http://second.{$this->base}/_probe/tenant")->assertNotFound();
    }

    public function test_the_new_host_serves_the_tenant_and_a_redirect_is_not_reported(): void
    {
        Exceptions::fake();
        $this->renameSecond('second-new');

        $this->getJson("http://second-new.{$this->base}/_probe/tenant")
            ->assertOk()
            ->assertJsonPath('slug', 'second-new');
        $this->get("http://second.{$this->base}/_probe/tenant")->assertStatus(302);

        Exceptions::assertNotReported(TenantSlugMovedException::class);
    }

    public function test_the_tenant_required_stack_redirects_too(): void
    {
        $this->renameSecond('second-new');

        $this->get("http://second.{$this->base}/_probe/tenant-required")
            ->assertStatus(302)
            ->assertRedirect($this->expectedOrigin('second-new') . '/_probe/tenant-required');
    }

    public function test_a_current_slug_wins_over_a_former_one_of_another_tenant(): void
    {
        $this->renameSecond('second-new');
        // Not reachable through Core, which reserves a former slug forever; a manual change must still be served right.
        DB::connection('landlord')->table('tenants')->where('slug', 'main')->update(['slug' => 'second']);

        $this->getJson("http://second.{$this->base}/_probe/tenant")
            ->assertOk()
            ->assertJsonPath('slug', 'second');
    }

    public function test_unresolvable_tenant_host_is_still_reported(): void
    {
        Exceptions::fake();

        $this->get("http://ghost.{$this->base}/_probe/tenant")->assertNotFound();

        Exceptions::assertReported(TenantNotFoundException::class);
    }

    public function test_single_mode_without_a_tenant_slug_stays_a_server_error(): void
    {
        Exceptions::fake();
        config(['tenancy.resolution' => 'single', 'tenancy.default_tenant_slug' => '']);

        $this->get("http://{$this->base}/")->assertStatus(500);

        Exceptions::assertReported(TenantNotFoundException::class);
    }

    public function test_foreign_hosts_are_404(): void
    {
        $this->get('http://example.com/_probe/tenant')->assertNotFound();
        $this->get("http://a.b.{$this->base}/_probe/tenant")->assertNotFound();
        $this->get("http://main.{$this->base}.evil.com/_probe/tenant")->assertNotFound();
    }

    public function test_host_matching_ignores_case_and_port(): void
    {
        $this->getJson('http://SECOND.' . mb_strtoupper($this->base) . ':8080/_probe/tenant')
            ->assertOk()
            ->assertJsonPath('slug', 'second');
    }

    public function test_livewire_update_endpoint_runs_in_the_tenant_of_its_host(): void
    {
        $route = $this->livewireUpdateRoute();
        $route->middleware(LivewireUpdateProbe::class);
        LivewireUpdateProbe::using(fn (): array => $this->probe());

        $path    = EndpointResolver::updatePath();
        $headers = ['X-Livewire' => 'true'];

        $this->postJson("http://second.{$this->base}{$path}", [], $headers)
            ->assertOk()
            ->assertJsonPath('slug', 'second');

        $this->postJson("http://main.{$this->base}{$path}", [], $headers)
            ->assertOk()
            ->assertJsonPath('slug', 'main');

        $this->postJson("http://ghost.{$this->base}{$path}", [], $headers)->assertNotFound();
        $this->postJson("http://example.com{$path}", [], $headers)->assertNotFound();
    }

    private function renameSecond(string $slug): void
    {
        $this->app->make(TenantRenamerInterface::class)->rename('00000000-0000-0000-0000-000000000002', $slug);
    }

    private function expectedOrigin(string $slug): string
    {
        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'https';
        $port   = parse_url($appUrl, PHP_URL_PORT);

        return "{$scheme}://{$slug}.{$this->base}" . (null === $port ? '' : ':' . $port);
    }

    private function provisionSecondTenant(): void
    {
        if ($this->onPostgres()) {
            DB::connection((string) config('tenancy.tenant_connection'))
                ->statement('CREATE SCHEMA ' . self::SECOND_SCHEMA);
        }

        DB::connection('landlord')->table('tenants')->insert([
            'id'          => '00000000-0000-0000-0000-000000000002',
            'slug'        => 'second',
            'schema_name' => self::SECOND_SCHEMA,
            'status'      => 'active',
            'config'      => '{}',
        ]);
    }

    private function onPostgres(): bool
    {
        return 'pgsql' === config('database.connections.' . config('tenancy.tenant_connection') . '.driver');
    }

    /**
     * What the request ran as: the tenant context and, on PostgreSQL, the schema in effect.
     *
     * @return array{slug: string|null, schema: string|null}
     */
    private function probe(): array
    {
        $context = $this->app->make(TenantContextInterface::class);

        return [
            'slug'   => $context->isResolved() ? $context->get()->getSlug() : null,
            'schema' => $this->onPostgres() ? DB::selectOne('SELECT current_schema() AS schema')?->schema : null,
        ];
    }

    private function livewireUpdateRoute(): RoutingRoute
    {
        foreach (Route::getRoutes() as $route) {
            if (str_ends_with((string) $route->getName(), 'livewire.update')) {
                return $route;
            }
        }

        $this->fail('Livewire update route is not registered.');
    }
}

/**
 * Stands in for the Livewire request handler at the end of the update route's middleware
 * stack: it reports what the stack in front of it set up, instead of processing a payload.
 */
final class LivewireUpdateProbe
{
    private static ?Closure $report = null;

    public static function using(Closure $report): void
    {
        self::$report = $report;
    }

    public function handle(Request $request, Closure $next): Response
    {
        return response()->json((self::$report)());
    }
}
