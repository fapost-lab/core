<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Database\TenantDatabaseManager;
use App\Domains\Tenancy\Settings\TenantSettings;
use App\Domains\Tenancy\ValueObjects\MigrationScope;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\FeatureTestCase;

/**
 * Migrations write permissions and settings with plain queries, so running them must drop the tenant's cache keys.
 */
final class TenantMigrationCacheResetTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(self::TENANT_ID, 'tenant'));
    }

    public function test_running_migrations_forgets_the_active_tenants_permission_cache_only(): void
    {
        $tenantId = self::TENANT_ID;
        $mine     = 'spatie.permission.cache.tenant.' . $tenantId;
        $other    = 'spatie.permission.cache.tenant.someone-else';
        $platform = 'spatie.permission.cache';

        foreach ([$mine, $other, $platform] as $key) {
            Cache::put($key, 'stale', 3600);
        }

        $this->app->make(TenantDatabaseManager::class)->runMigrations(MigrationScope::tenant());

        $this->assertFalse(Cache::has($mine));
        $this->assertTrue(Cache::has($other));
        $this->assertTrue(Cache::has($platform));
    }

    public function test_running_migrations_forgets_the_active_tenants_settings_keys_only(): void
    {
        $mine  = 'tenant.' . self::TENANT_ID . '.settings.' . TenantSettings::class;
        $other = 'tenant.someone-else.settings.' . TenantSettings::class;

        foreach ([$mine, $other] as $key) {
            Cache::put($key, 'stale', 3600);
        }

        $this->app->make(TenantDatabaseManager::class)->runMigrations(MigrationScope::tenant());

        $this->assertFalse(Cache::has($mine));
        $this->assertTrue(Cache::has($other));
    }

    public function test_running_migrations_with_no_tenant_active_forgets_nothing(): void
    {
        $this->app->make(TenantContextInterface::class)->reset();

        $keys = ['spatie.permission.cache.tenant.' . self::TENANT_ID, 'tenant.' . self::TENANT_ID . '.settings.' . TenantSettings::class];

        foreach ($keys as $key) {
            Cache::put($key, 'stale', 3600);
        }

        $this->app->make(TenantDatabaseManager::class)->runMigrations(MigrationScope::tenant());

        foreach ($keys as $key) {
            $this->assertTrue(Cache::has($key), $key);
        }
    }

    public function test_a_permission_inserted_behind_the_cache_is_found_after_the_migrations_ran(): void
    {
        $tenantId            = self::TENANT_ID;
        $registrar           = $this->app->make(PermissionRegistrar::class);
        $registrar->cacheKey = 'spatie.permission.cache.tenant.' . $tenantId;
        $registrar->forgetCachedPermissions();
        $registrar->getPermissions();

        DB::table(config('permission.table_names.permissions'))->insert([
            'id'         => (string) Str::ulid(),
            'name'       => 'behind_the_cache_probe',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->app->make(TenantDatabaseManager::class)->runMigrations(MigrationScope::tenant());
        // What the next switch into the tenant does: the in-memory collection goes, the cache store is read.
        $registrar->clearPermissionsCollection();

        $this->assertSame('behind_the_cache_probe', Permission::findByName('behind_the_cache_probe', 'web')->name);
    }
}
