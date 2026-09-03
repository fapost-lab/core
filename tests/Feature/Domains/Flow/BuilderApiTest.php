<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Models\TenantEvent;
use App\Domains\Staff\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

final class BuilderApiTest extends FeatureTestCase
{
    public function test_show_returns_builder_flow_dto(): void
    {
        $draft = $this->draft();
        FlowTrigger::query()->create([
            'tenant_id'    => $draft->tenant_id,
            'assistant_id' => $draft->assistant_id,
            'flow_id'      => $draft->flow_id,
            'type'         => 'event',
            'is_active'    => true,
            'priority'     => 100,
            'config'       => ['event_name' => 'employee_registered'],
        ]);
        TenantEvent::query()->create([
            'tenant_id'  => $draft->tenant_id,
            'event_name' => 'employee_registered',
        ]);
        $user = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->get("/builder/flows/{$draft->flow_id}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($draft): void {
                $page->component('FlowBuilder/FlowEditor')
                    ->has('flow', fn (AssertableInertia $flow): AssertableInertia => $flow
                        ->where('flowId', $draft->flow_id)
                        ->where('name', $draft->name)
                        ->where('draftVersion', $draft->draft_version)
                        ->where('trigger.type', 'event')
                        ->where('availableEvents.0', 'employee_registered')
                        ->etc());
            });
    }

    public function test_show_returns_builder_flow_dto_when_tenant_events_table_is_missing(): void
    {
        Schema::dropIfExists('tenant_events');

        $draft = $this->draft();
        $user  = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->get("/builder/flows/{$draft->flow_id}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($draft): void {
                $page->component('FlowBuilder/FlowEditor')
                    ->has('flow', fn (AssertableInertia $flow): AssertableInertia => $flow
                        ->where('flowId', $draft->flow_id)
                        ->where('availableEvents', [])
                        ->etc());
            });
    }

    public function test_save_draft_returns_409_on_version_conflict(): void
    {
        $draft = $this->draft(['draft_version' => 5]);
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
        $draft = $this->draft(['draft_version' => 5]);
        $user  = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->putJson("/builder/flows/{$draft->flow_id}/draft", [
                'draft_version' => 5,
                'definition'    => ['nodes' => []],
                'trigger'       => [
                    'type'      => 'message',
                    'is_active' => true,
                    'priority'  => 100,
                    'config'    => [
                        'keywords' => ['help'],
                        'phrases'  => [],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonFragment(['draft_version' => 6]);

        $this->assertDatabaseHas('flow_triggers', [
            'flow_id' => $draft->flow_id,
            'type'    => 'message',
        ]);
    }

    /**
     * SaveDraft does NOT validate (per builder UX contract — drafts can be
     * saved in any state). Duplicate trigger keywords are caught by the
     * /validate endpoint and on publish; they no longer block saving the
     * draft itself. This lets authors create empty/half-edited flows and
     * attach them as default_flow_id without forcing immediate cleanup.
     */
    public function test_save_draft_persists_even_with_duplicate_message_keywords(): void
    {
        $draft        = $this->draft(['draft_version' => 5]);
        $otherFlowId  = (string) Str::uuid();

        FlowTrigger::query()->create([
            'tenant_id'    => $draft->tenant_id,
            'assistant_id' => $draft->assistant_id,
            'flow_id'      => $otherFlowId,
            'type'         => 'message',
            'is_active'    => true,
            'priority'     => 10,
            'config'       => [
                'keywords' => ['help'],
                'phrases'  => [],
            ],
        ]);

        $user = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->putJson("/builder/flows/{$draft->flow_id}/draft", [
                'draft_version' => 5,
                'definition'    => ['nodes' => []],
                'trigger'       => [
                    'type'      => 'message',
                    'is_active' => true,
                    'priority'  => 100,
                    'config'    => [
                        'keywords' => ['HELP!!!'],
                        'phrases'  => [],
                    ],
                ],
            ])
            ->assertOk();

        $draft->refresh();
        $this->assertSame(6, $draft->draft_version);
        $this->assertDatabaseHas('flow_triggers', [
            'flow_id' => $draft->flow_id,
            'type'    => 'message',
        ]);
    }

    public function test_validate_endpoint_reports_duplicate_message_keywords(): void
    {
        $draft       = $this->draft(['draft_version' => 5]);
        $otherFlowId = (string) Str::uuid();

        FlowTrigger::query()->create([
            'tenant_id'    => $draft->tenant_id,
            'assistant_id' => $draft->assistant_id,
            'flow_id'      => $otherFlowId,
            'type'         => 'message',
            'is_active'    => true,
            'priority'     => 10,
            'config'       => [
                'keywords' => ['help'],
                'phrases'  => [],
            ],
        ]);

        $user = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->postJson("/builder/flows/{$draft->flow_id}/validate", [
                'definition' => ['nodes' => []],
                'trigger'    => [
                    'type'      => 'message',
                    'is_active' => true,
                    'priority'  => 100,
                    'config'    => [
                        'keywords' => ['HELP!!!'],
                        'phrases'  => [],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('errors.0.code', 'duplicate_exact_trigger_keyword');
    }

    public function test_validate_returns_trigger_errors_when_trigger_payload_is_invalid(): void
    {
        $draft = $this->draft();
        $user  = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->postJson("/builder/flows/{$draft->flow_id}/validate", [
                'definition' => [[
                    'id'      => 'input_1',
                    'type'    => 'input',
                    'version' => 1,
                    'config' => [
                        'variable' => [
                            'name'    => 'answer',
                            'type'    => 'text',
                            'storage' => 'session',
                            'group'   => null,
                        ],
                    ],
                ]],
                'trigger' => [
                    'type'      => 'event',
                    'is_active' => true,
                    'priority'  => 100,
                    'config'    => ['event_name' => 'Bad Name'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('errors.0.path', 'trigger.config');
    }

    public function test_validate_returns_valid_for_empty_flow_without_trigger(): void
    {
        $draft = $this->draft();
        $user  = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->postJson("/builder/flows/{$draft->flow_id}/validate", [
                'definition' => [
                    'nodes' => [],
                    'edges' => [],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('errors', []);
    }

    public function test_validate_returns_error_when_event_trigger_selects_unknown_event(): void
    {
        $draft = $this->draft();
        $user  = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->postJson("/builder/flows/{$draft->flow_id}/validate", [
                'definition' => [[
                    'id'      => 'input_1',
                    'type'    => 'input',
                    'version' => 1,
                    'config' => [
                        'variable' => [
                            'name'    => 'answer',
                            'type'    => 'text',
                            'storage' => 'session',
                            'group'   => null,
                        ],
                    ],
                ]],
                'trigger' => [
                    'type'      => 'event',
                    'is_active' => true,
                    'priority'  => 100,
                    'config'    => ['event_name' => 'employee_registered'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('errors.0.code', 'unknown_event_trigger_selection');
    }

    public function test_validate_returns_duplicate_keyword_error_for_exact_message_trigger_conflict(): void
    {
        $draft       = $this->draft();
        $otherFlowId = (string) Str::uuid();

        FlowTrigger::query()->create([
            'tenant_id'    => $draft->tenant_id,
            'assistant_id' => $draft->assistant_id,
            'flow_id'      => $otherFlowId,
            'type'         => 'message',
            'is_active'    => true,
            'priority'     => 10,
            'config'       => [
                'keywords' => ['vacation'],
                'phrases'  => [],
            ],
        ]);

        $user = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->postJson("/builder/flows/{$draft->flow_id}/validate", [
                'definition' => [[
                    'id'      => 'input_1',
                    'type'    => 'input',
                    'version' => 1,
                    'config' => [
                        'variable' => [
                            'name'    => 'answer',
                            'type'    => 'text',
                            'storage' => 'session',
                            'group'   => null,
                        ],
                    ],
                ]],
                'trigger' => [
                    'type'      => 'message',
                    'is_active' => true,
                    'priority'  => 100,
                    'config'    => [
                        'keywords' => ['Vacation!!!'],
                        'phrases'  => [],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('errors.0.code', 'duplicate_exact_trigger_keyword');
    }

    public function test_publish_returns_422_on_invalid_draft(): void
    {
        $draft = $this->draftWithUnconnectedOutput();
        $user  = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->postJson("/builder/flows/{$draft->flow_id}/publish")
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'errors']);
    }

    /**
     * Same contract as duplicate-keywords above: unknown event_name flags
     * a validation error but does not block draft persistence.
     */
    public function test_save_draft_persists_even_with_unknown_event_trigger(): void
    {
        $draft = $this->draft(['draft_version' => 5]);
        $user  = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->putJson("/builder/flows/{$draft->flow_id}/draft", [
                'draft_version' => 5,
                'definition'    => ['nodes' => []],
                'trigger'       => [
                    'type'      => 'event',
                    'is_active' => true,
                    'priority'  => 100,
                    'config'    => [
                        'event_name' => 'employee_registered',
                    ],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('flow_triggers', [
            'flow_id' => $draft->flow_id,
            'type'    => 'event',
        ]);
    }

    public function test_validate_endpoint_reports_unknown_event_trigger(): void
    {
        $draft = $this->draft(['draft_version' => 5]);
        $user  = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $this->actingAs($user)
            ->postJson("/builder/flows/{$draft->flow_id}/validate", [
                'definition' => ['nodes' => []],
                'trigger'    => [
                    'type'      => 'event',
                    'is_active' => true,
                    'priority'  => 100,
                    'config'    => [
                        'event_name' => 'employee_registered',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('errors.0.code', 'unknown_event_trigger_selection');
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

    public function test_node_types_exposes_comment_annotation_with_a_text_field(): void
    {
        $user = $this->staffUser();
        /** @var \Illuminate\Contracts\Auth\Authenticatable $user */

        $types = $this->actingAs($user)
            ->getJson('/builder/node-types')
            ->assertOk()
            ->json('data');

        $comment = collect($types)->firstWhere('type', 'comment');

        $this->assertNotNull($comment);
        $this->assertTrue($comment['annotation']);
        $this->assertSame('text', $comment['config_schema']['text']['type']);
        $this->assertSame('Note', $comment['config_schema']['text']['label']);
        $this->assertFalse($comment['config_schema']['text']['variable_picker']);
    }

    private function staffUser(): User
    {
        return User::factory()->createOne();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function draft(array $attributes = []): FlowDraft
    {
        $assistant = Assistant::factory()->create();

        return FlowDraft::factory()->create([
            'tenant_id'    => $assistant->tenant_id,
            'assistant_id' => $assistant->getKey(),
            ...$attributes,
        ]);
    }

    private function draftWithUnconnectedOutput(): FlowDraft
    {
        $assistant = Assistant::factory()->create();

        return FlowDraft::factory()->withUnconnectedOutput()->create([
            'tenant_id'    => $assistant->tenant_id,
            'assistant_id' => $assistant->getKey(),
        ]);
    }
}
