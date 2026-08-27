<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Staff;

use App\Domains\Staff\Enums\Permission as PermissionEnum;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\Permission;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Policies\RolePolicy;
use App\Domains\Staff\Policies\UserPolicy;
use App\Domains\Staff\Services\RoleWriterService;
use App\Domains\Staff\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AuthorizationHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_cannot_update_admin_user(): void
    {
        $manager = User::factory()->create();
        $admin   = User::factory()->create();

        $manager->assignRole($this->createRole(RoleEnum::ContentManager->value, RoleEnum::ContentManager->priority()));
        $admin->assignRole($this->createRole(RoleEnum::Admin->value, RoleEnum::Admin->priority()));
        $this->grantManageUsers($manager);

        $policy = new UserPolicy();

        self::assertFalse($policy->update($manager, $admin));
        self::assertFalse($policy->delete($manager, $admin));
    }

    public function test_manager_cannot_activate_or_deactivate_user_in_policy_and_service(): void
    {
        $manager = User::factory()->create();
        $target  = User::factory()->create(['is_active' => false]);

        $manager->assignRole($this->createRole(RoleEnum::ContentManager->value, RoleEnum::ContentManager->priority()));
        $target->assignRole($this->createRole(RoleEnum::Analyst->value, RoleEnum::Analyst->priority()));
        $this->grantManageUsers($manager);

        $policy = new UserPolicy();
        self::assertFalse($policy->activate($manager, $target));
        self::assertFalse($policy->deactivate($manager, $target));

        $service = app(UserService::class);

        $this->expectException(ValidationException::class);
        $service->activate($manager, $target);
    }

    public function test_manager_cannot_manage_roles_in_policy_and_service(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole($this->createRole(RoleEnum::ContentManager->value, RoleEnum::ContentManager->priority()));
        $this->grantManageUsers($manager);

        $role   = $this->createRole('custom_role', 10);
        $policy = new RolePolicy();

        self::assertFalse($policy->viewAny($manager));
        self::assertFalse($policy->create($manager));
        self::assertFalse($policy->update($manager, $role));
        self::assertFalse($policy->delete($manager, $role));

        $this->expectException(ValidationException::class);
        app(RoleWriterService::class)->create($manager, [
            'name'              => 'new_role',
            'display_name'      => 'New role',
            'permission_groups' => [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => 'database/migrations/tenant', '--force' => true];
    }

    private function grantManageUsers(User $user): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name'       => PermissionEnum::ManageUsers->value,
            'guard_name' => 'web',
        ]);

        $user->givePermissionTo($permission);
    }

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
