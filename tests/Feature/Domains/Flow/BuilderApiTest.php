<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Staff\Models\User;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

final class BuilderApiTest extends FeatureTestCase
{
    public function test_show_returns_builder_flow_dto(): void
    {
        $draft = FlowDraft::factory()->create();
        $user  = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->get("/builder/flows/{$draft->flow_id}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('Builder/Flow')
                    ->has('flow_id')
                    ->has('name')
                    ->has('draft_version')
                    ->has('published_version')
                    ->has('definition')
                    ->has('published_at');
            });
    }

    public function test_save_draft_returns_409_on_version_conflict(): void
    {
        $draft = FlowDraft::factory()->create(['draft_version' => 5]);
        $user  = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->putJson("/builder/flows/{$draft->flow_id}/draft", [
                'draft_version' => 3,
                'definition'    => ['nodes' => []],
            ])
            ->assertStatus(409)
            ->assertJsonFragment(['error' => 'draft_conflict']);
    }

    public function test_save_draft_increments_draft_version(): void
    {
        $draft = FlowDraft::factory()->create(['draft_version' => 5]);
        $user  = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->putJson("/builder/flows/{$draft->flow_id}/draft", [
                'draft_version' => 5,
                'definition'    => ['nodes' => []],
            ])
            ->assertOk()
            ->assertJsonFragment(['draft_version' => 6]);
    }

    public function test_publish_returns_422_on_invalid_draft(): void
    {
        $draft = FlowDraft::factory()->withUnconnectedOutput()->create();
        $user  = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->postJson("/builder/flows/{$draft->flow_id}/publish")
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'errors']);
    }

    public function test_node_types_returns_registered_handlers(): void
    {
        $user = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->getJson('/builder/node-types')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['type', 'version', 'label', 'category', 'config_schema'],
                ],
            ]);
    }

    private function staffUser(): User
    {
        return User::factory()->createOne();
    }
}
