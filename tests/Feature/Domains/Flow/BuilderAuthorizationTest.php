<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * The builder API is gated by the flow-draft permissions and by access to the flow's assistant.
 */
final class BuilderAuthorizationTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_flow_endpoints_are_forbidden_without_permission(): void
    {
        $draft = $this->draft();
        $user  = User::factory()->create();
        $user->assistants()->attach($draft->assistant);

        $this->assertFlowEndpointsForbidden($user, $draft);
    }

    public function test_flow_endpoints_are_forbidden_for_an_unassigned_assistant(): void
    {
        $draft = $this->draft();
        $user  = $this->userWithFlowPermissions();

        $this->assertFlowEndpointsForbidden($user, $draft);
    }

    public function test_flow_endpoints_work_with_permission_and_assignment(): void
    {
        $draft = $this->draft();
        $user  = $this->userWithFlowPermissions();
        $user->assistants()->attach($draft->assistant);

        $this->actingAs($user)->get("/builder/flows/{$draft->flow_id}")->assertOk()->assertSee('fapost-theme', false);
        $this->actingAs($user)
            ->putJson("/builder/flows/{$draft->flow_id}/draft", $this->draftPayload($draft))
            ->assertOk();
        $this->actingAs($user)
            ->postJson("/builder/flows/{$draft->flow_id}/validate", ['definition' => ['nodes' => []]])
            ->assertOk();
        $this->actingAs($user)
            ->postJson("/builder/flows/{$draft->flow_id}/publish")
            ->assertSuccessful();
    }

    public function test_publish_requires_the_publish_permission(): void
    {
        $draft = $this->draft();
        $user  = User::factory()->create();
        $user->givePermissionTo(Permission::ManageFlowDefinitions->value);
        $user->assistants()->attach($draft->assistant);

        $this->actingAs($user)->get("/builder/flows/{$draft->flow_id}")->assertOk();
        $this->actingAs($user)->postJson("/builder/flows/{$draft->flow_id}/publish")->assertForbidden();
    }

    public function test_nonexistent_flow_is_not_found_rather_than_forbidden(): void
    {
        $flowId = (string) Str::uuid();
        $user   = $this->userWithFlowPermissions();

        $this->actingAs($user)->get("/builder/flows/{$flowId}")->assertNotFound();
        $this->actingAs($user)
            ->putJson("/builder/flows/{$flowId}/draft", ['draft_version' => 1, 'definition' => ['nodes' => []]])
            ->assertNotFound();
        $this->actingAs($user)
            ->postJson("/builder/flows/{$flowId}/validate", ['definition' => ['nodes' => []]])
            ->assertNotFound();
        $this->actingAs($user)->postJson("/builder/flows/{$flowId}/publish")->assertNotFound();
    }

    public function test_option_endpoints_are_forbidden_without_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        foreach (['/builder/node-types', '/builder/tags', '/builder/staff', '/builder/assistants'] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_option_endpoints_are_allowed_with_permission(): void
    {
        $user = $this->userWithFlowPermissions();

        $this->actingAs($user);

        foreach (['/builder/node-types', '/builder/tags', '/builder/staff', '/builder/assistants'] as $url) {
            $this->getJson($url)->assertOk();
        }
    }

    public function test_call_test_is_forbidden_without_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/builder/call/test', ['config' => ['transport' => 'http']])
            ->assertForbidden();
    }

    public function test_call_test_passes_authorization_with_permission(): void
    {
        $response = $this->actingAs($this->userWithFlowPermissions())
            ->postJson('/builder/call/test', ['config' => ['transport' => 'http']]);

        $this->assertNotSame(403, $response->getStatusCode());
    }

    private function assertFlowEndpointsForbidden(User $user, FlowDraft $draft): void
    {
        $this->actingAs($user)->get("/builder/flows/{$draft->flow_id}")->assertForbidden();
        $this->actingAs($user)
            ->putJson("/builder/flows/{$draft->flow_id}/draft", $this->draftPayload($draft))
            ->assertForbidden();
        $this->actingAs($user)
            ->postJson("/builder/flows/{$draft->flow_id}/validate", ['definition' => ['nodes' => []]])
            ->assertForbidden();
        $this->actingAs($user)->postJson("/builder/flows/{$draft->flow_id}/publish")->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function draftPayload(FlowDraft $draft): array
    {
        return ['draft_version' => $draft->draft_version, 'definition' => ['nodes' => []]];
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
