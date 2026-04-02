<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Staff;

use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\Permission as PermissionModel;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Policies\UserPolicy;
use App\Domains\Staff\Services\UserService;
use Database\Seeders\RoleSeeder;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Reset Spatie permission cache between tests.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // -----------------------------------------------------------------------
    // Role hierarchy — UserPolicy
    // -----------------------------------------------------------------------

    public function test_actor_cannot_update_own_roles(): void
    {
        $role  = $this->createRole('manager', 50);
        $actor = User::factory()->create();
        $actor->assignRole($role);

        $policy = new UserPolicy();

        $this->assertFalse($policy->updateRoles($actor, $actor, [$role->id]));
    }

    public function test_actor_cannot_assign_role_with_equal_or_higher_priority(): void
    {
        $actorRole  = $this->createRole('manager', 50);
        $targetRole = $this->createRole('analyst', 30);
        $equalRole  = $this->createRole('co_manager', 50);

        $actor  = User::factory()->create();
        $target = User::factory()->create();
        $actor->assignRole($actorRole);
        $target->assignRole($targetRole);

        $policy = new UserPolicy();

        // Same priority as actor → forbidden
        $this->assertFalse($policy->updateRoles($actor, $target, [$equalRole->id]));
    }

    public function test_actor_cannot_edit_user_with_higher_priority(): void
    {
        $lowRole  = $this->createRole('manager', 50);
        $highRole = $this->createRole('admin', 100);

        $actor  = User::factory()->create();
        $target = User::factory()->create();
        $actor->assignRole($lowRole);
        $target->assignRole($highRole);

        $policy = new UserPolicy();

        $this->assertFalse($policy->updateProfile($actor, $target));
    }

    public function test_actor_can_assign_lower_priority_role(): void
    {
        $actorRole  = $this->createRole('manager', 50);
        $targetRole = $this->createRole('analyst', 30);
        $lowerRole  = $this->createRole('viewer', 10);

        $actor  = User::factory()->create();
        $target = User::factory()->create();
        $actor->assignRole($actorRole);
        $target->assignRole($targetRole);

        $policy = new UserPolicy();

        // lowerRole has priority 10, actor has priority 50 → allowed
        $this->assertTrue($policy->updateRoles($actor, $target, [$lowerRole->id]));
    }

    public function test_actor_can_edit_own_profile_fields(): void
    {
        $actor  = User::factory()->create();
        $policy = new UserPolicy();

        $this->assertTrue($policy->updateProfile($actor, $actor));
    }

    // -----------------------------------------------------------------------
    // Deactivation — User model + EnsureUserIsActive
    // -----------------------------------------------------------------------

    public function test_deactivated_user_cannot_login(): void
    {
        $panel = Mockery::mock(Panel::class);
        $user  = new User(['status' => UserStatus::Active->value, 'is_active' => false]);

        $this->assertFalse($user->canAccessPanel($panel));
    }

    // -----------------------------------------------------------------------
    // Deactivation guards — UserService
    // -----------------------------------------------------------------------

    public function test_cannot_deactivate_self(): void
    {
        $adminRole = $this->createRole(RoleEnum::Admin->value, RoleEnum::Admin->priority());
        $actor     = User::factory()->create();
        $actor->assignRole($adminRole);

        $this->expectException(ValidationException::class);

        app(UserService::class)->deactivate($actor, $actor);
    }

    public function test_cannot_deactivate_last_admin(): void
    {
        $adminRole = $this->createRole(RoleEnum::Admin->value, RoleEnum::Admin->priority());
        $actor     = User::factory()->create();
        $lastAdmin = User::factory()->create(['is_active' => true]);

        $actor->assignRole($adminRole);
        $lastAdmin->assignRole($adminRole);

        // Deactivate actor first, leaving lastAdmin as the only admin.
        $actor->update(['is_active' => false]);

        $this->expectException(ValidationException::class);

        // Non-admin actor tries to deactivate the last admin — guard triggers.
        app(UserService::class)->deactivate($actor, $lastAdmin);
    }

    // -----------------------------------------------------------------------
    // Seeder idempotency — RoleSeeder
    // -----------------------------------------------------------------------

    public function test_role_seeder_is_idempotent(): void
    {
        $seeder = new RoleSeeder();

        $seeder->run();
        $rolesAfterFirst       = Role::count();
        $permissionsAfterFirst = PermissionModel::count();

        $seeder->run();

        $this->assertSame($rolesAfterFirst, Role::count());
        $this->assertSame($permissionsAfterFirst, PermissionModel::count());
    }

    /**
     * Only run tenant-schema migrations (skip landlord migrations which require PostgreSQL).
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => 'database/migrations/tenant', '--force' => true];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function createRole(string $name, int $priority, bool $isSystem = false): Role
    {
        return Role::query()->create([
            'name'       => $name,
            'guard_name' => 'web',
            'priority'   => $priority,
            'is_system'  => $isSystem,
        ]);
    }
}
