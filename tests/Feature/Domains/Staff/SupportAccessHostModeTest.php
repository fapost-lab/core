<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Tenancy\Contracts\SupportAccessInterface;
use Fapost\Foundation\Tenancy\DTO\SupportAccessRequest;
use Tests\Feature\Concerns\RunsInHostMode;
use Tests\Feature\FeatureTestCase;

/**
 * In `host` mode the grant names the tenant's own host, and only that host redeems it.
 */
final class SupportAccessHostModeTest extends FeatureTestCase
{
    use RunsInHostMode;

    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = (string) config('tenancy.base_domain');
        $this->provisionSecondTenant();
        $this->seed(TenantAclSeeder::class);
        config(['tenancy.support_access.enabled' => true]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreTenancyResolution();
    }

    public function test_the_grant_names_the_tenant_host_and_that_host_redeems_it(): void
    {
        $grant = $this->grant();

        $this->assertStringContainsString("main.{$this->base}", $grant->enterUrl);
        $this->assertStringEndsWith('/support/enter', $grant->enterUrl);

        $this->post("http://main.{$this->base}/support/enter", ['token' => $grant->token()])
            ->assertRedirect("http://main.{$this->base}/admin");
    }

    public function test_the_base_domain_and_foreign_hosts_answer_404(): void
    {
        $grant = $this->grant();

        foreach (["http://{$this->base}", "http://ghost.{$this->base}", 'http://example.com'] as $origin) {
            $this->post($origin . '/support/enter', ['token' => $grant->token()])->assertNotFound();
        }
    }

    public function test_another_tenants_host_refuses_the_token(): void
    {
        $this->post("http://second.{$this->base}/support/enter", ['token' => $this->grant()->token()])->assertForbidden();
    }

    private function grant(): \Fapost\Foundation\Tenancy\DTO\SupportAccessGrant
    {
        return $this->app->make(SupportAccessInterface::class)
            ->issue(new SupportAccessRequest(self::TENANT_ID, 'operator:7', 'Olga', 'olga@example.com'));
    }
}
