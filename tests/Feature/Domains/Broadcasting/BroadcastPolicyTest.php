<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Broadcasting;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Policies\BroadcastPolicy;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\FeatureTestCase;

/**
 * {@see BroadcastPolicy}: ManageBroadcast plus assistant assignment for concrete broadcasts.
 */
final class BroadcastPolicyTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    /**
     * @var list<string>
     */
    private const array RECORD_ABILITIES = ['view', 'update', 'delete', 'send', 'cancel'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_user_without_permission_is_denied_everything(): void
    {
        $broadcast = $this->broadcast();
        $user      = User::factory()->create();
        $user->assistants()->attach($broadcast->assistant);

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', Broadcast::class));
        $this->assertFalse(Gate::forUser($user)->allows('create', Broadcast::class));
        $this->assertFalse(Gate::forUser($user)->allows('deleteAny', Broadcast::class));

        foreach (self::RECORD_ABILITIES as $ability) {
            $this->assertFalse(Gate::forUser($user)->allows($ability, $broadcast), $ability);
        }
    }

    public function test_user_with_permission_but_not_assigned_is_denied_record_abilities(): void
    {
        $broadcast = $this->broadcast();
        $user      = User::factory()->create();
        $user->givePermissionTo(Permission::ManageBroadcast->value);

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', Broadcast::class));
        $this->assertTrue(Gate::forUser($user)->allows('create', Broadcast::class));

        foreach (self::RECORD_ABILITIES as $ability) {
            $this->assertFalse(Gate::forUser($user)->allows($ability, $broadcast), $ability);
        }
    }

    public function test_user_with_permission_and_assignment_is_allowed(): void
    {
        $broadcast = $this->broadcast();
        $user      = User::factory()->create();
        $user->givePermissionTo(Permission::ManageBroadcast->value);
        $user->assistants()->attach($broadcast->assistant);

        $this->assertTrue(Gate::forUser($user)->allows('deleteAny', Broadcast::class));

        foreach (self::RECORD_ABILITIES as $ability) {
            $this->assertTrue(Gate::forUser($user)->allows($ability, $broadcast), $ability);
        }
    }

    public function test_assignment_to_another_assistant_does_not_help(): void
    {
        $broadcast = $this->broadcast();
        $user      = User::factory()->create();
        $user->givePermissionTo(Permission::ManageBroadcast->value);
        $user->assistants()->attach(Assistant::factory()->create(['tenant_id' => self::TENANT_ID]));

        $this->assertFalse(Gate::forUser($user)->allows('send', $broadcast));
    }

    public function test_admin_is_allowed_without_assignment(): void
    {
        $broadcast = $this->broadcast();
        $admin     = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);

        $this->assertTrue(Gate::forUser($admin)->allows('send', $broadcast));
    }

    private function broadcast(): Broadcast
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);

        return Broadcast::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $assistant->getKey(),
            'name'         => 'Promo',
            'message'      => ['en' => 'Hello'],
            'target_type'  => 'all',
            'status'       => BroadcastStatus::Draft->value,
        ]);
    }
}
