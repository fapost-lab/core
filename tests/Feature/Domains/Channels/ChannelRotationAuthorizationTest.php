<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Channels;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Staff\Enums\Permission as PermissionEnum;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\Permission;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Filament\Assistant\Resources\Channels\Tables\ChannelsTable;
use App\Filament\Resources\Assistants\Pages\EditAssistant;
use App\Filament\Resources\Assistants\RelationManagers\ChannelsRelationManager;
use Filament\Actions\Action;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Mockery;
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

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
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

    public function test_assistant_panel_hides_rotate_action_without_permission(): void
    {
        $this->assertRotateActionVisibility(fn (): Action => $this->rotateAction(
            ChannelsTable::configureRecordActions(
                Table::make(Mockery::mock(HasTable::class)),
                Mockery::mock(ChannelServiceInterface::class),
            )->getRecordActions(),
        ));
    }

    public function test_admin_relation_manager_hides_rotate_action_without_permission(): void
    {
        $this->assertRotateActionVisibility(function (): Action {
            $relationManager              = app(ChannelsRelationManager::class);
            $relationManager->ownerRecord = Assistant::query()->findOrFail($this->channel->assistant_id);
            $relationManager->pageClass   = EditAssistant::class;

            return $this->rotateAction($relationManager->table(Table::make($relationManager))->getRecordActions());
        });
    }

    /**
     * Filament caches an action's visibility per record, so each user gets a freshly built action.
     *
     * @param  callable(): Action  $buildAction
     */
    private function assertRotateActionVisibility(callable $buildAction): void
    {
        $manager = $this->userWithRole(RoleEnum::ContentManager, [PermissionEnum::ManageAssistants]);
        $manager->assistants()->attach($this->channel->assistant_id);
        $this->actingAs($manager);

        $this->assertTrue($buildAction()->record($this->channel)->isHidden());

        $this->actingAs($this->userWithRole(RoleEnum::Admin, []));

        $this->assertTrue($buildAction()->record($this->channel)->isVisible());
    }

    /**
     * @param  array<array-key, mixed>  $actions
     */
    private function rotateAction(array $actions): Action
    {
        $action = collect($actions)->first(
            static fn (mixed $action): bool => $action instanceof Action && 'rotateWebhookHash' === $action->getName(),
        );

        $this->assertInstanceOf(Action::class, $action);

        return $action;
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
