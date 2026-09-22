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
 * {@see \App\Domains\Channels\Policies\ChannelPolicy}: channel management requires
 * {@see PermissionEnum::ManageChannels} plus assignment to the channel's assistant.
 * {@see PermissionEnum::ManageAssistants} alone must no longer grant channel access —
 * that is the regression this task fixes.
 */
final class ChannelPolicyTest extends FeatureTestCase
{
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create());
    }

    public function test_manage_channels_assigned_to_the_assistant_can_view_update_delete_create(): void
    {
        $user = $this->userWithCustomRole([PermissionEnum::ManageChannels]);
        $user->assistants()->attach($this->channel->assistant_id);

        $gate = Gate::forUser($user->fresh());

        $this->assertTrue($gate->allows('viewAny', Channel::class));
        $this->assertTrue($gate->allows('view', $this->channel));
        $this->assertTrue($gate->allows('update', $this->channel));
        $this->assertTrue($gate->allows('delete', $this->channel));
        $this->assertTrue($gate->allows('create', [Channel::class, $this->channel->assistant]));
    }

    public function test_manage_assistants_alone_assigned_to_the_assistant_is_denied_everywhere(): void
    {
        $user = $this->userWithCustomRole([PermissionEnum::ManageAssistants]);
        $user->assistants()->attach($this->channel->assistant_id);

        $gate = Gate::forUser($user->fresh());

        $this->assertFalse($gate->allows('viewAny', Channel::class));
        $this->assertFalse($gate->allows('view', $this->channel));
        $this->assertFalse($gate->allows('update', $this->channel));
        $this->assertFalse($gate->allows('delete', $this->channel));
        $this->assertFalse($gate->allows('create', [Channel::class, $this->channel->assistant]));
    }

    public function test_manage_channels_without_assignment_is_denied_on_a_specific_channel(): void
    {
        $user = $this->userWithCustomRole([PermissionEnum::ManageChannels]);

        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('viewAny', Channel::class));
        $this->assertFalse($gate->allows('view', $this->channel));
        $this->assertFalse($gate->allows('update', $this->channel));
        $this->assertFalse($gate->allows('delete', $this->channel));
        $this->assertFalse($gate->allows('create', [Channel::class, $this->channel->assistant]));
    }

    public function test_admin_is_allowed_without_assignment(): void
    {
        $admin = $this->userWithRole(RoleEnum::Admin, []);

        $gate = Gate::forUser($admin);

        $this->assertTrue($gate->allows('viewAny', Channel::class));
        $this->assertTrue($gate->allows('view', $this->channel));
        $this->assertTrue($gate->allows('update', $this->channel));
        $this->assertTrue($gate->allows('delete', $this->channel));
        $this->assertTrue($gate->allows('create', [Channel::class, $this->channel->assistant]));
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
            'name'       => 'channel_manager',
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
