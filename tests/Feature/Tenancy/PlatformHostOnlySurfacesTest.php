<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\OnlyOnPlatformHosts;
use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureTestCase;

/**
 * Horizon and Telescope are the installation's, not a tenant's: in `host` mode they answer on the
 * base domain and platform subdomains, and 404 on a tenant host.
 */
final class PlatformHostOnlySurfacesTest extends FeatureTestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.resolution' => 'host', 'tenancy.platform_subdomains' => ['ops']]);
        $this->base = (string) config('tenancy.base_domain');

        Route::middleware([OnlyOnPlatformHosts::class, 'web'])->get('/_probe/ops', fn (): string => 'ops');
    }

    public function test_a_tenant_host_does_not_reach_it(): void
    {
        $this->get("http://main.{$this->base}/_probe/ops")->assertNotFound();
    }

    public function test_the_base_domain_and_a_platform_subdomain_do(): void
    {
        $this->get("http://{$this->base}/_probe/ops")->assertOk();
        $this->get("http://ops.{$this->base}/_probe/ops")->assertOk();
    }

    public function test_single_mode_keeps_the_one_host_working(): void
    {
        config(['tenancy.resolution' => 'single']);

        $this->get('http://anything.test/_probe/ops')->assertOk();
    }

    public function test_horizon_is_guarded_by_it_and_closed_on_a_platform_host(): void
    {
        $this->assertContains(OnlyOnPlatformHosts::class, config('horizon.middleware'));
        $this->assertContains(OnlyOnPlatformHosts::class, config('telescope.middleware'));

        // Not served on a tenant host at all; on the base domain the gate (empty allow-list) refuses.
        $this->get("http://main.{$this->base}/horizon")->assertNotFound();
        $this->get("http://{$this->base}/horizon")->assertForbidden();
    }
}
