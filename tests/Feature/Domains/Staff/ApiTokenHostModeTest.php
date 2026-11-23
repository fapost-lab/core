<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\ApiTokenService;
use App\Domains\Staff\Support\ApiTokenGuard;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\RunsInHostMode;
use Tests\Feature\FeatureTestCase;

/**
 * The `api-token` stack in `host` mode: the tenant comes from the host, the base domain and hosts that name no tenant
 * answer 404, and a token is found only in the tenant of the host it is presented on (`adr-20261119-tenant-api-tokens`
 * §5).
 */
final class ApiTokenHostModeTest extends FeatureTestCase
{
    use RunsInHostMode;

    private string $base;

    private string $plain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = (string) config('tenancy.base_domain');
        $this->provisionSecondTenant();
        $this->seed(TenantAclSeeder::class);

        Route::middleware(ApiTokenGuard::MIDDLEWARE_GROUP)->prefix('api/v1')
            ->get('probe', static fn () => response()->json(['token' => Auth::id()]));

        $main = Tenant::query()->where('slug', 'main')->firstOrFail();

        $this->plain = $this->app->make(TenantSwitcher::class)->runForTenant($main, function (): string {
            $admin = User::factory()->create();
            $admin->assignRole(RoleEnum::Admin->value);

            return $this->app->make(ApiTokenService::class)->issue($admin, 'CRM', [Permission::ManageSettings->value])->plain;
        });

        $this->assertFalse($this->app->make(TenantContextInterface::class)->isResolved());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreTenancyResolution();
    }

    public function test_the_token_works_on_its_tenants_host(): void
    {
        $this->get("http://main.{$this->base}/api/v1/probe", ['Authorization' => 'Bearer ' . $this->plain])
            ->assertOk()
            ->assertHeaderMissing('Set-Cookie');
    }

    public function test_the_base_domain_and_hosts_that_name_no_tenant_answer_404(): void
    {
        foreach (["http://{$this->base}", "http://ghost.{$this->base}", 'http://example.com'] as $origin) {
            Auth::forgetGuards();
            $this->get($origin . '/api/v1/probe', ['Authorization' => 'Bearer ' . $this->plain])->assertNotFound();
        }
    }

    public function test_another_tenants_host_does_not_know_the_token(): void
    {
        if (! $this->onPostgres()) {
            $this->markTestSkipped('Both tenants share one SQLite database; the schemas differ only on PostgreSQL.');
        }

        $this->get("http://second.{$this->base}/api/v1/probe", ['Authorization' => 'Bearer ' . $this->plain])
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthenticated']);
    }
}
