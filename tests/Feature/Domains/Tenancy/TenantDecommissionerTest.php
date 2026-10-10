<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryWriterInterface;
use App\Domains\Tenancy\Services\TenantDecommissioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Decommissioning removes one tenant's webhook registry entries, schema and
 * landlord row, and nothing of any other tenant.
 */
final class TenantDecommissionerTest extends FeatureTestCase
{
    public function test_it_removes_the_tenant_its_registry_entries_and_its_schema_only(): void
    {
        $doomed = $this->insertTenant('loadtest-doomed');
        $kept   = $this->insertTenant('keep-me');

        $writer = $this->app->make(WebhookRegistryWriterInterface::class);
        $writer->write('hash-doomed', $doomed, 'a1', 'c1', 'telegram', 'secret');
        $writer->write('hash-kept', $kept, 'a2', 'c2', 'telegram', 'secret');

        $databases = $this->createMock(TenantDatabaseManagerInterface::class);
        $databases->method('schemaExists')->willReturn(true);
        $databases->expects($this->once())->method('dropSchema')
            ->with($this->callback(fn (TenantInterface $tenant): bool => $tenant->getId() === $doomed->getId()));
        $this->app->instance(TenantDatabaseManagerInterface::class, $databases);

        try {
            $this->app->make(TenantDecommissioner::class)->decommission($doomed);

            $tenants = $this->app->make(TenantRepositoryInterface::class);
            $this->assertNull($tenants->findById($doomed->getId()));
            $this->assertNotNull($tenants->findById($kept->getId()));

            $this->assertSame(
                ['hash-kept'],
                DB::connection('landlord')->table('webhook_registry')->pluck('webhook_public_hash')->all(),
            );
            $this->assertSame(0, Redis::exists('webhook:hash-doomed'));
            $this->assertSame(1, Redis::exists('webhook:hash-kept'));
        } finally {
            Redis::del('webhook:hash-doomed', 'webhook:hash-kept');
        }
    }

    public function test_it_removes_the_former_slugs_of_the_tenant_with_it(): void
    {
        $doomed = $this->insertTenant('loadtest-doomed');
        $kept   = $this->insertTenant('keep-me');
        $now    = now();

        $tenants = $this->app->make(TenantRepositoryInterface::class);
        $tenants->addFormerSlug('loadtest-old', $doomed->getId(), $now->copy()->addDay(), $now);
        $tenants->addFormerSlug('keep-old', $kept->getId(), null, $now);

        $databases = $this->createMock(TenantDatabaseManagerInterface::class);
        $databases->method('schemaExists')->willReturn(false);
        $this->app->instance(TenantDatabaseManagerInterface::class, $databases);

        $this->app->make(TenantDecommissioner::class)->decommission($doomed);

        $this->assertSame(['keep-old'], DB::connection('landlord')->table('tenant_slug_aliases')->pluck('slug')->all());
    }

    public function test_find_by_slug_prefix_matches_the_prefix_literally(): void
    {
        $this->insertTenant('loadtest-abc-1');
        $this->insertTenant('loadtestx-1');

        $slugs = array_map(
            static fn (TenantInterface $tenant): string => $tenant->getSlug(),
            $this->app->make(TenantRepositoryInterface::class)->findBySlugPrefix('loadtest-'),
        );

        $this->assertSame(['loadtest-abc-1'], $slugs);
    }

    private function insertTenant(string $slug): TenantInterface
    {
        $id = (string) Str::ulid()->toRfc4122();

        DB::connection('landlord')->table('tenants')->insert([
            'id'          => $id,
            'slug'        => $slug,
            'schema_name' => 'tenant_' . str_replace('-', '_', $slug),
            'status'      => 'active',
            'config'      => '{}',
        ]);

        return $this->app->make(TenantRepositoryInterface::class)->getById($id);
    }
}
