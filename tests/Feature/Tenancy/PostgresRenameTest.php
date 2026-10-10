<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Repositories\TenantRepository;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Fapost\Foundation\Tenancy\Contracts\TenantRenamerInterface;
use Fapost\Foundation\Tenancy\Enums\RenameFailure;
use Fapost\Foundation\Tenancy\Exceptions\TenantRenameFailedException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Psr\Log\NullLogger;
use Tests\Feature\FeatureTestCase;

/**
 * Renaming against a real PostgreSQL tenant schema and the real landlord constraints. Skipped unless the
 * tenant and landlord connections are PostgreSQL; run it only with every connection on a test database.
 * The schema is created inside the test's transaction and rolled back with it.
 */
final class PostgresRenameTest extends FeatureTestCase
{
    public function test_a_renamed_tenant_still_switches_into_its_original_schema(): void
    {
        $this->requirePostgres();
        config(['tenancy.resolution' => 'host']);

        $id = $this->provisionedTenant('pgren-old');

        $renamed = $this->app->make(TenantRenamerInterface::class)->rename($id, 'pgren-new');

        $this->assertSame('pgren-new', $renamed->slug);
        $tenant = Tenant::on('landlord')->findOrFail($id);
        $this->assertSame('pgren-new', $tenant->slug);
        $this->assertSame('tenant_pgren_old', $tenant->schema_name);

        $seen = $this->app->make(TenantSwitcher::class)->runForTenant($tenant, static fn (): array => [
            DB::selectOne('SELECT current_schema() AS schema')?->schema,
            User::query()->count(),
        ]);

        $this->assertSame(['tenant_pgren_old', 1], $seen, 'The tenant migrations and its admin are where they were.');

        $this->expectException(TenantRenameFailedException::class);
        $this->app->make(TenantRenamerInterface::class)->rename($this->provisionedTenant('pgren-other'), 'pgren-old');
    }

    public function test_the_taken_check_is_still_refused_on_the_real_unique_indexes(): void
    {
        $this->requirePostgres();
        config(['tenancy.resolution' => 'host']);
        $first  = $this->provisionedTenant('pgren-a');
        $second = $this->provisionedTenant('pgren-b');

        try {
            $this->app->make(TenantRenamerInterface::class)->rename($second, 'pgren-a');
            $this->fail('Expected SlugTaken.');
        } catch (TenantRenameFailedException $e) {
            $this->assertSame(RenameFailure::SlugTaken, $e->reason);
        }

        $this->assertSame('pgren-a', Tenant::on('landlord')->findOrFail($first)->slug);
        $this->assertSame('pgren-b', Tenant::on('landlord')->findOrFail($second)->slug);
    }

    public function test_former_slugs_go_with_the_tenant_row_through_the_foreign_key(): void
    {
        $this->requirePostgres();
        config(['tenancy.resolution' => 'host']);
        $id = $this->provisionedTenant('pgren-fk');
        $this->app->make(TenantRenamerInterface::class)->rename($id, 'pgren-fk-new');

        $this->assertSame(1, DB::connection('landlord')->table('tenant_slug_aliases')->where('tenant_id', $id)->count());

        DB::connection('landlord')->table('tenants')->where('id', $id)->delete();

        $this->assertSame(0, DB::connection('landlord')->table('tenant_slug_aliases')->where('tenant_id', $id)->count());
    }

    public function test_the_slug_claim_lock_is_an_advisory_transaction_lock(): void
    {
        $this->requirePostgres();
        $repository = new TenantRepository();

        $this->assertSame(0, $this->advisoryLocksHeld());

        $repository->lockSlugClaims();
        $repository->lockSlugClaims();

        $this->assertSame(1, $this->advisoryLocksHeld(), 'Taking it again in the same transaction is harmless.');
    }

    private function requirePostgres(): void
    {
        if ('pgsql' !== DB::connection('landlord')->getDriverName()
            || 'pgsql' !== DB::connection((string) config('tenancy.tenant_connection'))->getDriverName()) {
            $this->markTestSkipped('Needs PostgreSQL on the landlord and tenant connections.');
        }
    }

    private function advisoryLocksHeld(): int
    {
        return (int) DB::connection('landlord')->selectOne(
            "SELECT count(*) AS held FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()",
        )?->held;
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
