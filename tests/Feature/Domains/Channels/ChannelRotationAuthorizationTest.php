<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Channels;

use App\Domains\Channels\Models\Channel;
use App\Domains\Staff\Enums\Permission as PermissionEnum;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\Permission;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\FeatureTestCase;

/**
 * Rotating a channel's webhook hash is gated by the sensitive
 * {@see PermissionEnum::RotateChannelToken}, not by {@see PermissionEnum::ManageAssistants}.
 */
final class ChannelRotationAuthorizationTest extends FeatureTestCase
{
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create());
    }

    public function test_content_manager_assigned_to_the_assistant_cannot_rotate(): void
    {
        $manager = $this->userWithRole(RoleEnum::ContentManager, [PermissionEnum::ManageAssistants]);
        $manager->assistants()->attach($this->channel->assistant_id);

        $this->assertFalse(Gate::forUser($manager)->allows('rotateWebhook', $this->channel));
    }

    public function test_role_with_rotate_permission_can_rotate_only_for_its_assistants(): void
    {
        $operator = $this->userWithCustomRole([PermissionEnum::RotateChannelToken]);

        $this->assertFalse(Gate::forUser($operator)->allows('rotateWebhook', $this->channel));

        $operator->assistants()->attach($this->channel->assistant_id);

        $this->assertTrue(Gate::forUser($operator->fresh())->allows('rotateWebhook', $this->channel));
    }

    public function test_admin_can_rotate_without_assignment(): void
    {
        $admin = $this->userWithRole(RoleEnum::Admin, []);

        $this->assertTrue(Gate::forUser($admin)->allows('rotateWebhook', $this->channel));
    }

    /**
     * @param  list<PermissionEnum>  $permissions
     */
    private function userWithRole(RoleEnum $roleEnum, array $permissions): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => $roleEnum->value, 'guard_name' => 'web'],
            ['priority' => $roleEnum->priority(), 'is_system' => true],
        );

        return $this->userWith($role, $permissions);
    }

    /**
     * @param  list<PermissionEnum>  $permissions
     */
    private function userWithCustomRole(array $permissions): User
    {
        $role = Role::query()->create([
            'name'       => 'channel_operator',
            'guard_name' => 'web',
            'priority'   => 0,
            'is_system'  => false,
        ]);

        return $this->userWith($role, $permissions);
    }

    /**
     * @param  list<PermissionEnum>  $permissions
     */
    private function userWith(Role $role, array $permissions): User
    {
        foreach ($permissions as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate([
                'name'       => $permission->value,
                'guard_name' => 'web',
            ]));
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
