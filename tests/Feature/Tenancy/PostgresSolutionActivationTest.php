<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Infrastructure\SolutionActivationEventRecord;
use App\Domains\Solution\Infrastructure\SolutionActivationRecord;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Repositories\TenantRepository;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Support\Facades\DB;
use Mockery;
use Psr\Log\NullLogger;
use Tests\Feature\FeatureTestCase;
use Tests\Support\InstallsFakeSolutions;

/**
 * Activation in two real tenant schemas: one tenant's choice is neither stored in, nor read from, the other's.
 * Skipped unless the tenant and landlord connections are PostgreSQL; run it only with every connection on a
 * test database. The schemas are created inside the test's transaction and rolled back with it.
 */
final class PostgresSolutionActivationTest extends FeatureTestCase
{
    use InstallsFakeSolutions;

    protected function tearDown(): void
    {
        $this->removeFakeSolutions();

        parent::tearDown();
    }

    public function test_activation_is_stored_in_the_schema_of_its_tenant_and_read_back_from_it(): void
    {
        $this->requirePostgres();
        config(['tenancy.resolution' => 'host']);
        $this->installSolutions('feedback', 'survey');

        $alpha = Tenant::on('landlord')->findOrFail($this->provisionedTenant('pgsol-alpha'));
        $beta  = Tenant::on('landlord')->findOrFail($this->provisionedTenant('pgsol-beta'));
        $this->switcher()->runForTenant($alpha, fn () => $this->app->make(SolutionActivator::class)->activate('feedback', ActivationActor::console()));
        $this->switcher()->runForTenant($beta, fn () => $this->app->make(SolutionActivator::class)->activate('survey', ActivationActor::console()));

        $this->app->forgetScopedInstances();

        $seen = $this->switcher()->runForTenant($alpha, fn (): array => [
            DB::selectOne('SELECT current_schema() AS schema')?->schema,
            $this->app->make(ActiveSolutions::class)->ids(),
            SolutionActivationRecord::query()->pluck('solution_id')->all(),
            SolutionActivationEventRecord::query()->pluck('solution_id')->all(),
        ]);
        $this->assertSame(['tenant_pgsol_alpha', ['feedback'], ['feedback'], ['feedback']], $seen);

        $this->app->forgetScopedInstances();

        $seen = $this->switcher()->runForTenant($beta, fn (): array => [
            DB::selectOne('SELECT current_schema() AS schema')?->schema,
            $this->app->make(ActiveSolutions::class)->ids(),
            SolutionActivationRecord::query()->pluck('solution_id')->all(),
        ]);
        $this->assertSame(['tenant_pgsol_beta', ['survey'], ['survey']], $seen);

        // One worker job that visits both tenants keeps their answers apart.
        $this->app->forgetScopedInstances();
        $both = $this->switcher()->runForTenant($alpha, function () use ($beta): array {
            $active = $this->app->make(ActiveSolutions::class);

            return [
                $active->ids(),
                $this->switcher()->runForTenant($beta, fn (): array => $active->ids()),
                $active->ids(),
            ];
        });
        $this->assertSame([['feedback'], ['survey'], ['feedback']], $both);
    }

    public function test_the_unique_index_keeps_one_row_per_solution(): void
    {
        $this->requirePostgres();
        config(['tenancy.resolution' => 'host']);
        $this->installSolutions('feedback');

        $tenant = Tenant::on('landlord')->findOrFail($this->provisionedTenant('pgsol-unique'));

        $this->app->make(TenantSwitcher::class)->runForTenant($tenant, function (): void {
            $activator = $this->app->make(SolutionActivator::class);
            $activator->activate('feedback', ActivationActor::console());
            $activator->activate('feedback', ActivationActor::console());

            $this->assertSame(1, SolutionActivationRecord::query()->count());
            $this->assertSame(1, SolutionActivationEventRecord::query()->count());
        });
    }

    /**
     * Resolved anew each time: the switcher is scoped, and a worker gets a fresh one with every job.
     */
    private function switcher(): TenantSwitcher
    {
        return $this->app->make(TenantSwitcher::class);
    }

    private function requirePostgres(): void
    {
        if ('pgsql' !== DB::connection('landlord')->getDriverName()
            || 'pgsql' !== DB::connection((string) config('tenancy.tenant_connection'))->getDriverName()) {
            $this->markTestSkipped('Needs PostgreSQL on the landlord and tenant connections.');
        }
    }

    private function provisionedTenant(string $slug): string
    {
        $webhooks = Mockery::mock(ChannelWebhookRegistryInterface::class);
        $webhooks->shouldReceive('warmup');

        $service = new TenantProvisioningService(
            new TenantRepository(),
            $this->app->make(TenantDatabaseManagerInterface::class),
            $this->app->make(TenantSwitcher::class),
            new AclBootstrapService(),
            $webhooks,
            new TenantSlugPolicy(['webhook']),
            new NullLogger(),
        );

        return $service->provision($slug, "admin@{$slug}.test", 'S3cret-p@ss-value', 'Admin')->getId();
    }
}
