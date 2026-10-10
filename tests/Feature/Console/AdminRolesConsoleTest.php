<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Database\Seeders\TenantAclSeeder;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;

/**
 * Staff roles in the admin panel, served by the console shell in its admin mode: only an administrator with
 * `ManageUsers` reaches them, permissions come from the catalogue only, a system role keeps its name and is never
 * deleted, and the tenant's permission cache is flushed after every write.
 */
final class AdminRolesConsoleTest extends InertiaConsoleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_the_list_shows_the_roles_inside_the_admin_shell(): void
    {
        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Roles/Index')
                ->where('navigation.mode', 'admin')
                ->where('urls.index', '/admin/roles')
                ->where('table.rows', fn ($rows): bool => collect($rows)->contains(static fn (array $row): bool => RoleEnum::Admin->value === $row['name']
                    && true === $row['isSystem']
                    && count(Permission::cases()) === $row['permissionsCount']))
                ->where('navigation.groups', fn ($groups): bool => collect($groups)
                    ->flatMap(static fn (array $group): array => $group['items'])
                    ->contains(static fn (array $item): bool => 'filament.admin.resources.roles.index' === $item['key'] && false === $item['external']))
                ->etc());
    }

    public function test_only_an_administrator_with_manage_users_reaches_roles(): void
    {
        $role    = $this->customRole();
        $manager = User::factory()->create();
        $manager->assignRole(RoleEnum::ContentManager->value);
        $manager->givePermissionTo(Permission::ManageUsers->value);

        foreach ([$manager, User::factory()->create()] as $denied) {
            $this->actingAs($denied)->get($this->url())->assertForbidden();
            $this->actingAs($denied)->get($this->url('/create'))->assertForbidden();
            $this->actingAs($denied)->post($this->url(), ['name' => 'x', 'permissions' => []])->assertForbidden();
            $this->actingAs($denied)->get($this->url("/{$role->getKey()}/edit"))->assertForbidden();
            $this->actingAs($denied)->put($this->url("/{$role->getKey()}"), ['name' => 'x', 'permissions' => []])->assertForbidden();
            $this->actingAs($denied)->delete($this->url("/{$role->getKey()}"))->assertForbidden();
            $this->actingAs($denied)->delete($this->url(), ['ids' => [$role->getKey()]])->assertForbidden();
        }

        $this->assertFalse(Role::query()->where('name', 'x')->exists());
        $this->assertNotNull($role->fresh());
    }

    public function test_the_form_shows_the_catalogue_in_groups(): void
    {
        $role = $this->customRole([Permission::ViewContacts]);

        $this->actingAs($this->admin())
            ->get($this->url("/{$role->getKey()}/edit"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Roles/Edit')
                ->where('role.permissions', [Permission::ViewContacts->value])
                ->where('catalogue', fn ($groups): bool => array_keys(Permission::groupedByGroup()) === collect($groups)->pluck('key')->all()
                    && collect($groups)->flatMap(static fn (array $group): array => $group['permissions'])
                        ->contains(static fn (array $permission): bool => Permission::ManageRoles->value === $permission['value'] && true === $permission['sensitive']))
                ->etc());
    }

    public function test_a_role_is_created_with_catalogue_permissions_only(): void
    {
        $this->actingAs($this->admin())
            ->post($this->url(), ['name' => 'support_agent', 'display_name' => 'Support agent', 'permissions' => [Permission::ViewContacts->value, Permission::ViewAnalytics->value]])
            ->assertRedirect($this->url())
            ->assertInertiaFlash('success', trans('console.roles.created'));

        $role = Role::query()->where('name', 'support_agent')->firstOrFail();
        $this->assertSame('Support agent', $role->display_name);
        $this->assertEqualsCanonicalizing([Permission::ViewContacts->value, Permission::ViewAnalytics->value], $role->permissions->pluck('name')->all());

        $this->actingAs($this->admin())
            ->post($this->url(), ['name' => 'other', 'permissions' => ['root_everything']])
            ->assertSessionHasErrors('permissions.0');
        $this->actingAs($this->admin())
            ->post($this->url(), ['name' => 'support_agent', 'permissions' => []])
            ->assertSessionHasErrors('name');
        $this->assertFalse(Role::query()->where('name', 'other')->exists());
    }

    public function test_an_update_replaces_the_permissions_and_flushes_the_cache(): void
    {
        $role = $this->customRole([Permission::ViewContacts]);
        $this->cacheStalePermissions();

        $this->actingAs($this->admin())
            ->put($this->url("/{$role->getKey()}"), ['name' => 'renamed', 'display_name' => '', 'permissions' => [Permission::ManageContacts->value]])
            ->assertRedirect($this->url())
            ->assertInertiaFlash('success', trans('console.roles.updated'));

        $role->refresh();
        $this->assertSame('renamed', $role->name);
        $this->assertNull($role->display_name);
        $this->assertSame([Permission::ManageContacts->value], $role->permissions->pluck('name')->all());
        $this->assertFalse($this->hasCachedPermissions());
    }

    public function test_a_system_role_keeps_its_name(): void
    {
        $analyst = Role::query()->where('name', RoleEnum::Analyst->value)->firstOrFail();

        $this->actingAs($this->admin())
            ->put($this->url("/{$analyst->getKey()}"), ['name' => 'hijacked', 'display_name' => 'Analysts', 'permissions' => [Permission::ViewAnalytics->value]])
            ->assertRedirect($this->url());

        $analyst->refresh();
        $this->assertSame(RoleEnum::Analyst->value, $analyst->name);
        $this->assertSame('Analysts', $analyst->display_name);
        $this->assertSame([Permission::ViewAnalytics->value], $analyst->permissions->pluck('name')->all());
    }

    public function test_a_custom_role_is_deleted_and_the_cache_flushed(): void
    {
        $role = $this->customRole([Permission::ViewContacts]);
        $this->cacheStalePermissions();

        $this->actingAs($this->admin())
            ->delete($this->url("/{$role->getKey()}"))
            ->assertRedirect($this->url())
            ->assertInertiaFlash('success', trans('console.roles.deleted'));

        $this->assertNull($role->fresh());
        $this->assertFalse($this->hasCachedPermissions());
    }

    public function test_a_system_role_is_never_deleted(): void
    {
        $admin  = $this->admin();
        $system = Role::query()->where('name', RoleEnum::Analyst->value)->firstOrFail();
        $custom = $this->customRole();

        $this->actingAs($admin)
            ->get($this->url("/{$system->getKey()}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.delete', false)->where('role.isSystem', true)->etc());

        $this->actingAs($admin)
            ->delete($this->url("/{$system->getKey()}"))
            ->assertInertiaFlash('error', trans('console.roles.refused.system_role'));
        $this->assertNotNull($system->fresh());

        $this->actingAs($admin)
            ->delete($this->url(), ['ids' => [$system->getKey(), $custom->getKey()]])
            ->assertInertiaFlash('success', trans('console.roles.deleted_partly', ['deleted' => 1, 'blocked' => 1]));
        $this->assertNotNull($system->fresh());
        $this->assertNull($custom->fresh());
    }

    public function test_a_malformed_or_unknown_id_is_not_found(): void
    {
        $this->actingAs($this->admin())->get($this->url('/not-an-id/edit'))->assertNotFound();
        $this->actingAs($this->admin())->get($this->url('/01J00000000000000000000000/edit'))->assertNotFound();
    }

    /**
     * Grants cached under the current tenant's key, as a request of that tenant would have left them.
     */
    private function cacheStalePermissions(): void
    {
        $this->app->make(TenantSwitcher::class)->runForTenant(
            Tenant::query()->firstOrFail(),
            fn (): mixed => $this->app->make(PermissionRegistrar::class)->getPermissions(),
        );

        $this->assertTrue($this->hasCachedPermissions());
    }

    private function hasCachedPermissions(): bool
    {
        return $this->app->make(PermissionRegistrar::class)->getCacheRepository()->has($this->tenantPermissionCacheKey());
    }

    private function tenantPermissionCacheKey(): string
    {
        return config('permission.cache.key') . '.tenant.' . Tenant::query()->firstOrFail()->getId();
    }

    private function url(string $suffix = ''): string
    {
        return $this->panelUrl("/admin/roles{$suffix}");
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function customRole(array $permissions = []): Role
    {
        $role = Role::query()->create(['name' => 'custom_role', 'guard_name' => 'web', 'priority' => 10, 'is_system' => false]);
        $role->syncPermissions(array_map(static fn (Permission $permission): string => $permission->value, $permissions));

        return $role;
    }
}
