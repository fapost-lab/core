<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\CoreOperatorAccess;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Operator\Contracts\OperatorAccessInterface;
use Fapost\Foundation\Operator\Enums\OperatorSurface;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

/**
 * Core's default answer to "may this request look at an operator screen".
 */
final class CoreOperatorAccessTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    /**
     * @return array<string, array{0: OperatorSurface}>
     */
    public static function surfaces(): array
    {
        $cases = [];

        foreach (OperatorSurface::cases() as $surface) {
            $cases[$surface->value] = [$surface];
        }

        return $cases;
    }

    public function test_core_binds_its_answer_to_the_contract(): void
    {
        $this->assertInstanceOf(CoreOperatorAccess::class, $this->app->make(OperatorAccessInterface::class));
    }

    #[DataProvider('surfaces')]
    public function test_single_an_admin_sees_every_surface(OperatorSurface $surface): void
    {
        $this->assertTrue($this->canView($this->userWithRole(RoleEnum::Admin), $surface));
    }

    public function test_single_a_user_with_the_permission_sees_the_surface(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ViewOperatorScreens->value);

        $this->assertTrue($this->canView($user));
    }

    public function test_single_a_custom_role_can_be_granted_the_permission(): void
    {
        $role = Role::query()->create(['name' => 'ops', 'guard_name' => 'web', 'priority' => 10, 'is_system' => false]);
        $role->givePermissionTo(Permission::ViewOperatorScreens->value);
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertTrue($this->canView($user));
    }

    public function test_single_content_managers_and_analysts_do_not_pass(): void
    {
        $this->assertFalse($this->canView($this->userWithRole(RoleEnum::ContentManager)));
        $this->assertFalse($this->canView($this->userWithRole(RoleEnum::Analyst)));
    }

    public function test_single_view_system_is_not_enough(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ViewSystem->value);

        $this->assertFalse($this->canView($user));
    }

    public function test_single_a_guest_does_not_pass(): void
    {
        $this->assertFalse($this->canView(null));
    }

    public function test_single_a_deactivated_or_not_yet_active_admin_does_not_pass(): void
    {
        $deactivated = $this->userWithRole(RoleEnum::Admin);
        $deactivated->forceFill(['is_active' => false])->save();
        $pending = $this->userWithRole(RoleEnum::Admin);
        $pending->forceFill(['status' => UserStatus::Pending])->save();

        $this->assertFalse($this->canView($deactivated));
        $this->assertFalse($this->canView($pending));
    }

    public function test_single_the_platform_support_user_does_not_pass_although_it_is_an_admin(): void
    {
        $support = $this->userWithRole(RoleEnum::Admin);
        $support->forceFill(['is_platform_support' => true])->save();

        $this->assertTrue($support->isAdmin());
        $this->assertFalse($this->canView($support->refresh()));
    }

    public function test_host_mode_is_closed_for_everyone(): void
    {
        config(['tenancy.resolution' => 'host']);

        $withPermission = User::factory()->create();
        $withPermission->givePermissionTo(Permission::ViewOperatorScreens->value);

        $this->assertFalse($this->canView($this->userWithRole(RoleEnum::Admin)));
        $this->assertFalse($this->canView($withPermission));
        $this->assertFalse($this->canView(null));
    }

    public function test_an_operator_layer_can_replace_the_answer(): void
    {
        $this->app->bind(OperatorAccessInterface::class, fn (): OperatorAccessInterface => new class () implements OperatorAccessInterface {
            public function canView(Request $request, OperatorSurface $surface): bool
            {
                return OperatorSurface::Pulse === $surface;
            }
        });
        $access = $this->app->make(OperatorAccessInterface::class);

        $this->assertTrue($access->canView(Request::create('/'), OperatorSurface::Pulse));
        $this->assertFalse($access->canView(Request::create('/'), OperatorSurface::Horizon));
    }

    private function canView(?User $user, OperatorSurface $surface = OperatorSurface::Horizon): bool
    {
        $request = Request::create('/');
        $request->setUserResolver(static fn (): ?User => $user);

        return $this->app->make(OperatorAccessInterface::class)->canView($request, $surface);
    }

    private function userWithRole(RoleEnum $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user;
    }
}
