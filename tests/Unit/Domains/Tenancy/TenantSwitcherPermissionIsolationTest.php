<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Cache\CacheManager;
use Mockery;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Runs the switcher against a real registrar over an array cache store and no database: the
 * permissions each tenant reads come only from the entry cached under that tenant's own key.
 */
final class TenantSwitcherPermissionIsolationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_tenants_read_only_their_own_cached_permissions_in_one_process(): void
    {
        $registrar = $this->registrarOverArrayStore();
        $this->cachePermission($registrar, 'perm.tenant.a', 'only-a');
        $this->cachePermission($registrar, 'perm.tenant.b', 'only-b');

        $switcher = $this->switcher($registrar);

        $seenByA = $switcher->runForTenant($this->tenant('a'), fn (): array => $registrar->getPermissions()->pluck('name')->all());
        $seenByB = $switcher->runForTenant($this->tenant('b'), fn (): array => $registrar->getPermissions()->pluck('name')->all());

        $this->assertSame(['only-a'], $seenByA);
        $this->assertSame(['only-b'], $seenByB);
        $this->assertSame('perm', $registrar->cacheKey);
    }

    public function test_nested_tenants_do_not_leak_permissions_into_the_outer_tenant(): void
    {
        $registrar = $this->registrarOverArrayStore();
        $this->cachePermission($registrar, 'perm.tenant.a', 'only-a');
        $this->cachePermission($registrar, 'perm.tenant.b', 'only-b');

        $switcher = $this->switcher($registrar);
        $seen     = [];

        $switcher->runForTenant($this->tenant('a'), function () use ($switcher, $registrar, &$seen): void {
            $seen[] = $registrar->getPermissions()->pluck('name')->all();

            $switcher->runForTenant($this->tenant('b'), function () use ($registrar, &$seen): void {
                $seen[] = $registrar->getPermissions()->pluck('name')->all();
            });

            $seen[] = $registrar->getPermissions()->pluck('name')->all();
        });

        $this->assertSame([['only-a'], ['only-b'], ['only-a']], $seen);
    }

    public function test_tenant_without_a_cached_entry_does_not_see_another_tenants_permissions(): void
    {
        $registrar = $this->registrarOverArrayStore();
        $this->cachePermission($registrar, 'perm.tenant.a', 'only-a');
        $this->cachePermission($registrar, 'perm.tenant.empty', null);

        $seen = $this->switcher($registrar)->runForTenant(
            $this->tenant('empty'),
            fn (): array => $registrar->getPermissions()->pluck('name')->all(),
        );

        $this->assertSame([], $seen);
    }

    public function test_registrar_key_returns_to_the_base_key_after_the_callback_throws(): void
    {
        $registrar = $this->registrarOverArrayStore();
        $switcher  = $this->switcher($registrar);

        try {
            $switcher->runForTenant($this->tenant('a'), function (): never {
                throw new RuntimeException('fail');
            });
            $this->fail('The callback failure must propagate.');
        } catch (RuntimeException) {
            $this->assertSame('perm', $registrar->cacheKey);
        }
    }

    private function registrarOverArrayStore(): PermissionRegistrar
    {
        config([
            'permission.cache.store' => 'array',
            'permission.cache.key'   => 'perm',
        ]);

        return new PermissionRegistrar($this->app->make(CacheManager::class));
    }

    private function switcher(PermissionRegistrar $registrar): TenantSwitcher
    {
        $dbManager = Mockery::mock(TenantDatabaseManagerInterface::class);
        $dbManager->shouldReceive('switchTo');
        $dbManager->shouldReceive('restore');

        return new TenantSwitcher(new TenantContext(), $dbManager, $registrar, 'perm');
    }

    /**
     * Stores spatie's serialized payload (see PermissionRegistrar::getSerializedPermissionsForCache),
     * with an empty alias map so the attribute names are kept as they are.
     */
    private function cachePermission(PermissionRegistrar $registrar, string $key, ?string $name): void
    {
        $permissions = null === $name
            ? []
            : [['id' => 1, 'name' => $name, 'guard_name' => 'web']];

        $registrar->getCacheRepository()->put($key, ['alias' => [], 'permissions' => $permissions, 'roles' => []]);
    }

    private function tenant(string $id): TenantInterface
    {
        $tenant = Mockery::mock(TenantInterface::class);
        $tenant->shouldReceive('getId')->andReturn($id);

        return $tenant;
    }
}
