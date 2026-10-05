<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Staff;

use App\Domains\Staff\Enums\Permission as PermissionEnum;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\Permission;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\RoleWriterService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\FeatureTestCase;

/**
 * The registrar is replaced by one that, like a request racing the writer, re-caches stale grants
 * right after every flush made inside a transaction. Only a flush after the commit can clear them.
 */
final class RoleWriterPermissionCacheTest extends FeatureTestCase
{
    private RacingPermissionRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrar = new RacingPermissionRegistrar($this->app->make('cache'));
        $this->app->instance(PermissionRegistrar::class, $this->registrar);
    }

    public function test_update_leaves_no_stale_permission_cache_after_commit(): void
    {
        $actor = $this->admin();
        $role  = Role::query()->create(['name' => 'custom_role', 'guard_name' => 'web', 'priority' => 10, 'is_system' => false]);
        $this->registrar->captureStalePayload();

        app(RoleWriterService::class)->update($actor, $role, [
            'name'              => 'custom_role',
            'display_name'      => 'Custom',
            'permission_groups' => ['users' => [PermissionEnum::ManageUsers->value]],
        ]);

        $this->assertTrue($this->registrar->racedInsideTransaction, 'The race was not simulated.');
        $this->assertFalse($this->registrar->getCacheRepository()->has($this->registrar->cacheKey));
    }

    public function test_create_leaves_no_stale_permission_cache_after_commit(): void
    {
        $actor = $this->admin();
        $this->registrar->captureStalePayload();

        app(RoleWriterService::class)->create($actor, [
            'name'              => 'new_role',
            'display_name'      => 'New role',
            'permission_groups' => ['users' => [PermissionEnum::ManageUsers->value]],
        ]);

        $this->assertTrue($this->registrar->racedInsideTransaction, 'The race was not simulated.');
        $this->assertFalse($this->registrar->getCacheRepository()->has($this->registrar->cacheKey));
    }

    public function test_rolled_back_update_does_not_flush_after_commit(): void
    {
        $actor = $this->admin();
        $role  = Role::query()->create(['name' => 'custom_role', 'guard_name' => 'web', 'priority' => 10, 'is_system' => false]);
        $this->registrar->captureStalePayload();

        try {
            DB::transaction(function () use ($actor, $role): void {
                app(RoleWriterService::class)->update($actor, $role, [
                    'name'              => 'custom_role',
                    'display_name'      => 'Custom',
                    'permission_groups' => [],
                ]);

                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
        }

        // Spatie's own flushes ran inside the transaction; the after-commit one was discarded.
        $this->assertSame(0, $this->registrar->flushesOutsideTransaction);
    }

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => 'database/migrations/tenant', '--force' => true];
    }

    private function admin(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole(Role::query()->create([
            'name'       => RoleEnum::Admin->value,
            'guard_name' => 'web',
            'priority'   => RoleEnum::Admin->priority(),
            'is_system'  => true,
        ]));
        $actor->givePermissionTo(Permission::query()->firstOrCreate([
            'name'       => PermissionEnum::ManageUsers->value,
            'guard_name' => 'web',
        ]));

        return $actor;
    }
}

/**
 * @internal Test double: re-caches stale grants after a flush made while a transaction is open.
 */
final class RacingPermissionRegistrar extends PermissionRegistrar
{
    public bool $racedInsideTransaction = false;

    public int $flushesOutsideTransaction = 0;

    /**
     * @var array<string, mixed>
     */
    public array $stalePayload = [];

    /**
     * Caches the grants as they are now: what a racing request would read before the commit.
     */
    public function captureStalePayload(): void
    {
        $this->forgetCachedPermissions();
        $this->clearPermissionsCollection();
        $this->getPermissions();

        $this->stalePayload              = $this->getCacheRepository()->get($this->cacheKey);
        $this->flushesOutsideTransaction = 0;
    }

    public function forgetCachedPermissions(): bool
    {
        $forgotten = parent::forgetCachedPermissions();

        if (DB::transactionLevel() <= 1) {
            $this->flushesOutsideTransaction++;
        } else {
            $this->getCacheRepository()->put($this->cacheKey, $this->stalePayload);
            $this->racedInsideTransaction = true;
        }

        return $forgotten;
    }
}
