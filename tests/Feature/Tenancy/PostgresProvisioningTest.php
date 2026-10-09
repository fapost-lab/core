<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Exceptions\TenantProvisioningException;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use App\Domains\Tenancy\Repositories\TenantRepository;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Support\Facades\DB;
use Mockery;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * AC-10: provisioning against a real PostgreSQL schema with the production database manager. The
 * run fails after the migrations, leaving a claimed schema behind, and the repeat finishes it.
 * Skipped unless the tenant connection is PostgreSQL. The tenant schema is created inside the
 * test's transaction and rolled back with it.
 */
final class PostgresProvisioningTest extends FeatureTestCase
{
    private const string PASSWORD = 'S3cret-p@ss-value';

    public function test_a_failed_run_is_resumed_on_a_real_schema(): void
    {
        $connection = (string) config('tenancy.tenant_connection');

        if ('pgsql' !== DB::connection($connection)->getDriverName()) {
            $this->markTestSkipped('Schema-per-tenant provisioning needs PostgreSQL.');
        }

        $failing  = true;
        $webhooks = Mockery::mock(ChannelWebhookRegistryInterface::class);
        $webhooks->shouldReceive('warmup')->andReturnUsing(static function () use (&$failing): void {
            if ($failing) {
                throw new RuntimeException('webhook store down');
            }
        });

        $repository = new TenantRepository();
        $database   = $this->app->make(TenantDatabaseManagerInterface::class);
        $service    = new TenantProvisioningService(
            $repository,
            $database,
            $this->app->make(TenantSwitcher::class),
            new AclBootstrapService(),
            $webhooks,
            new TenantSlugPolicy(['webhook']),
            new NullLogger(),
        );

        $id = $service->reserveSlug('pgfull', 'pg-key')->getId();

        $this->assertSame(0, $this->schemaCount('tenant_pgfull'), 'Reserving creates no schema.');

        try {
            $service->provisionReserved($id, 'admin@pgfull.test', self::PASSWORD, 'Admin');
            $this->fail('Expected the webhooks step to fail.');
        } catch (TenantProvisioningException $e) {
            $this->assertSame($id, $e->tenantId);
        }

        $row = Tenant::on('landlord')->findOrFail($id);
        $this->assertSame(TenantStatus::Pending, $row->status);
        $this->assertSame('webhooks: ' . RuntimeException::class, $row->provisioning_error);
        $this->assertNotNull($row->schema_claimed_at);
        $this->assertSame(1, $this->schemaCount('tenant_pgfull'), 'The half-built schema stays for the repeat.');

        $failing = false;
        $service->provisionReserved($id, 'admin@pgfull.test', self::PASSWORD, 'Admin');

        $this->assertSame(TenantStatus::Active, Tenant::on('landlord')->findOrFail($id)->status);
        $this->assertSame(1, $this->tenantUsers($id));
        $this->assertSame(0, $this->app->make(TenantContextInterface::class)->isResolved() ? 1 : 0, 'The tenant switch was restored.');
    }

    private function schemaCount(string $schema): int
    {
        return DB::connection((string) config('tenancy.tenant_connection'))
            ->table('information_schema.schemata')
            ->where('schema_name', $schema)
            ->count();
    }

    private function tenantUsers(string $id): int
    {
        $tenant = Tenant::on('landlord')->findOrFail($id);

        return (int) $this->app->make(TenantSwitcher::class)->runForTenant($tenant, static fn (): int => User::query()->count());
    }
}
