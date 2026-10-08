<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Policies\FlowDraftPolicy;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\FeatureTestCase;

/**
 * {@see FlowDraftPolicy}: draft abilities need the permission and access to the draft's assistant.
 */
final class FlowDraftPolicyTest extends FeatureTestCase
{
    /**
     * @var list<string>
     */
    private const array RECORD_ABILITIES = ['view', 'update', 'delete', 'publish'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_user_without_permission_is_denied_even_when_assigned(): void
    {
        $draft = $this->draft();
        $user  = User::factory()->create();
        $user->assistants()->attach($draft->assistant);

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', FlowDraft::class));

        foreach (self::RECORD_ABILITIES as $ability) {
            $this->assertFalse(Gate::forUser($user)->allows($ability, $draft), $ability);
        }
    }

    public function test_user_with_permissions_but_not_assigned_is_denied(): void
    {
        $draft = $this->draft();
        $user  = $this->userWithFlowPermissions();

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', FlowDraft::class));
        $this->assertTrue(Gate::forUser($user)->allows('create', FlowDraft::class));

        foreach (self::RECORD_ABILITIES as $ability) {
            $this->assertFalse(Gate::forUser($user)->allows($ability, $draft), $ability);
        }
    }

    public function test_user_with_permissions_and_assignment_is_allowed(): void
    {
        $draft = $this->draft();
        $user  = $this->userWithFlowPermissions();
        $user->assistants()->attach($draft->assistant);

        foreach (self::RECORD_ABILITIES as $ability) {
            $this->assertTrue(Gate::forUser($user)->allows($ability, $draft), $ability);
        }
    }

    public function test_publish_needs_publish_permission_even_when_assigned(): void
    {
        $draft = $this->draft();
        $user  = User::factory()->create();
        $user->givePermissionTo(Permission::ManageFlowDefinitions->value);
        $user->assistants()->attach($draft->assistant);

        $this->assertTrue(Gate::forUser($user)->allows('update', $draft));
        $this->assertFalse(Gate::forUser($user)->allows('publish', $draft));
    }

    public function test_admin_is_allowed_without_assignment(): void
    {
        $draft = $this->draft();
        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);

        foreach (self::RECORD_ABILITIES as $ability) {
            $this->assertTrue(Gate::forUser($admin)->allows($ability, $draft), $ability);
        }
    }

    private function userWithFlowPermissions(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageFlowDefinitions->value, Permission::PublishFlow->value);

        return $user;
    }

    private function draft(): FlowDraft
    {
        $assistant = Assistant::factory()->create();

        return FlowDraft::factory()->create([
            'tenant_id'    => $assistant->tenant_id,
            'assistant_id' => $assistant->getKey(),
        ]);
    }
}
