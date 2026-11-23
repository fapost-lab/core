<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Staff\Enums\BuilderMode;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantAccessMode;

/**
 * The builder mode a staff user chose is stored on their own row, handed to the builder page, and guarded like the
 * rest of the builder.
 */
final class BuilderPreferenceTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_the_mode_is_stored_on_the_signed_in_users_row_only(): void
    {
        $user  = $this->userWithFlowPermissions();
        $other = $this->userWithFlowPermissions();

        $this->actingAs($user)
            ->putJson('/builder/preferences', ['builder_mode' => 'graph'])
            ->assertOk()
            ->assertExactJson(['builder_mode' => 'graph']);

        $this->assertSame(BuilderMode::Graph, $user->fresh()->builder_mode);
        $this->assertNull($other->fresh()->builder_mode);
    }

    public function test_an_unknown_or_missing_mode_is_refused_and_nothing_is_stored(): void
    {
        $user = $this->userWithFlowPermissions();

        $this->actingAs($user)->putJson('/builder/preferences', ['builder_mode' => 'canvas'])
            ->assertUnprocessable()->assertJsonValidationErrors('builder_mode');
        $this->actingAs($user)->putJson('/builder/preferences', [])
            ->assertUnprocessable()->assertJsonValidationErrors('builder_mode');

        $this->assertNull($user->fresh()->builder_mode);
    }

    public function test_a_user_without_the_flow_draft_permission_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/builder/preferences', ['builder_mode' => 'graph'])->assertForbidden();

        $this->assertNull($user->fresh()->builder_mode);
    }

    public function test_a_guest_is_unauthenticated(): void
    {
        $this->putJson('/builder/preferences', ['builder_mode' => 'graph'])->assertUnauthorized();
    }

    public function test_the_builder_page_carries_the_users_mode_and_defaults_to_the_tree(): void
    {
        $draft = $this->draft();
        $user  = $this->userWithFlowPermissions();
        $user->assistants()->attach($draft->assistant);

        $this->assertBuilderMode($user, $draft, 'tree');

        $user->forceFill(['builder_mode' => BuilderMode::Graph])->save();

        $this->assertBuilderMode($user->fresh(), $draft, 'graph');
    }

    public function test_a_stopped_tenant_refuses_the_write(): void
    {
        $user = $this->userWithFlowPermissions();
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped());
        $this->app->forgetScopedInstances();

        $this->actingAs($user)
            ->putJson('/builder/preferences', ['builder_mode' => 'graph'])
            ->assertStatus(423)
            ->assertJsonPath('error', 'tenant_stopped');

        $this->assertNull($user->fresh()->builder_mode);
    }

    private function assertBuilderMode(User $user, FlowDraft $draft, string $expected): void
    {
        $this->actingAs($user)
            ->get("/builder/flows/{$draft->flow_id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('FlowBuilder/FlowEditor')
                ->where('flow.builderMode', $expected)
                ->etc());
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
            'nodes'        => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
        ]);
    }
}
