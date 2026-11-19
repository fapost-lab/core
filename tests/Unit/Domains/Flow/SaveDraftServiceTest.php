<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Exceptions\DraftVersionConflictException;
use App\Domains\Flow\Exceptions\InvalidTriggerPayloadException;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Services\SaveDraftService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SaveDraftServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('flow_drafts');
        Schema::create('flow_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('flow_id')->unique();
            $table->string('kind', 16)->default('flow');
            $table->uuid('assistant_id')->nullable();
            $table->integer('draft_version')->default(1);
            $table->string('name');
            $table->json('nodes')->nullable();
            $table->json('edges')->nullable();
            $table->json('triggers')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('flow_triggers');
        Schema::create('flow_triggers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('assistant_id')->nullable();
            $table->uuid('flow_id');
            $table->string('type', 32);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->json('config');
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('tenant_events');
        Schema::create('tenant_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('event_name', 120);
            $table->timestamps();
        });
    }

    public function test_throws_draft_version_conflict_exception_on_stale_draft_version(): void
    {
        $draft   = FlowDraft::factory()->create(['draft_version' => 5]);
        $service = app(SaveDraftService::class);

        $this->expectException(DraftVersionConflictException::class);

        $service->execute($draft->flow_id, [], [], null, expectedDraftVersion: 3);
    }

    public function test_updates_nodes_stores_triggers_and_returns_incremented_version(): void
    {
        $draft = FlowDraft::factory()->create([
            'draft_version' => 2,
            'nodes'         => [],
        ]);
        $trigger = $this->trigger();

        $newVersion = app(SaveDraftService::class)->execute(
            $draft->flow_id,
            ['node_1' => ['id' => 'node_1', 'type' => 'input']],
            [],
            [$trigger],
            expectedDraftVersion: 2,
        );

        $this->assertSame(3, $newVersion);
        $draft->refresh();
        $this->assertSame(3, $draft->draft_version);
        $this->assertIsArray($draft->nodes);
        $this->assertSame('input', $draft->nodes['node_1']['type']);
        $this->assertEquals([$trigger], $draft->triggers);
    }

    public function test_saving_never_makes_a_trigger_live(): void
    {
        $draft = FlowDraft::factory()->create(['draft_version' => 1, 'nodes' => []]);

        app(SaveDraftService::class)->execute($draft->flow_id, [], [], [$this->trigger()], expectedDraftVersion: 1);

        $this->assertDatabaseCount('flow_triggers', 0);
    }

    public function test_it_keeps_the_draft_list_and_live_rows_when_triggers_payload_is_null(): void
    {
        $stored = [$this->trigger()];
        $draft  = FlowDraft::factory()->create([
            'draft_version' => 2,
            'nodes'         => [],
            'triggers'      => $stored,
        ]);

        FlowTrigger::query()->create([
            'tenant_id'    => $draft->tenant_id,
            'assistant_id' => $draft->assistant_id,
            'flow_id'      => $draft->flow_id,
            'type'         => 'message',
            'config'       => ['keywords' => ['live'], 'phrases' => []],
        ]);

        app(SaveDraftService::class)->execute($draft->flow_id, [], [], null, expectedDraftVersion: 2);

        $this->assertEquals($stored, $draft->refresh()->triggers);
        $this->assertDatabaseCount('flow_triggers', 1);
    }

    public function test_null_payload_leaves_a_never_written_list_null(): void
    {
        $draft = FlowDraft::factory()->create(['draft_version' => 1, 'nodes' => []]);

        app(SaveDraftService::class)->execute($draft->flow_id, [], [], null, expectedDraftVersion: 1);

        $this->assertNull($draft->refresh()->triggers);
    }

    public function test_an_empty_list_clears_the_draft_list_but_not_the_live_rows(): void
    {
        $draft = FlowDraft::factory()->create([
            'draft_version' => 1,
            'nodes'         => [],
            'triggers'      => [$this->trigger()],
        ]);

        FlowTrigger::query()->create([
            'tenant_id' => $draft->tenant_id,
            'flow_id'   => $draft->flow_id,
            'type'      => 'message',
            'config'    => ['keywords' => ['live'], 'phrases' => []],
        ]);

        app(SaveDraftService::class)->execute($draft->flow_id, [], [], [], expectedDraftVersion: 1);

        $this->assertSame([], $draft->refresh()->triggers);
        $this->assertDatabaseCount('flow_triggers', 1);
    }

    public function test_it_stores_the_list_normalized_with_lowercased_ids_and_defaults(): void
    {
        $draft = FlowDraft::factory()->create(['draft_version' => 1, 'nodes' => []]);
        $id    = (string) Str::uuid();

        app(SaveDraftService::class)->execute(
            $draft->flow_id,
            [],
            [],
            [['id' => mb_strtoupper($id), 'type' => 'message', 'config' => []]],
            expectedDraftVersion: 1,
        );

        $this->assertEquals(
            [['id' => $id, 'type' => 'message', 'is_active' => true, 'priority' => 100, 'config' => []]],
            $draft->refresh()->triggers,
        );
    }

    public function test_it_rejects_a_malformed_list_without_touching_the_draft(): void
    {
        $draft = FlowDraft::factory()->create(['draft_version' => 1, 'nodes' => []]);

        try {
            app(SaveDraftService::class)->execute($draft->flow_id, [], [], [['id' => 'nope', 'type' => 'message', 'config' => []]], expectedDraftVersion: 1);
            $this->fail('A trigger without a UUID id must be rejected.');
        } catch (InvalidTriggerPayloadException) {
            $this->assertSame(1, $draft->refresh()->draft_version);
            $this->assertNull($draft->triggers);
        }
    }

    public function test_a_template_accepts_an_empty_list_and_refuses_a_non_empty_one(): void
    {
        $draft   = FlowDraft::factory()->template()->create(['draft_version' => 1, 'nodes' => []]);
        $service = app(SaveDraftService::class);

        $service->execute($draft->flow_id, [], [], [], expectedDraftVersion: 1);
        $this->assertSame([], $draft->refresh()->triggers);

        $this->expectException(InvalidTriggerPayloadException::class);

        $service->execute($draft->flow_id, [], [], [$this->trigger()], expectedDraftVersion: 2);
    }

    public function test_it_registers_emitted_events_tenant_wide_on_draft_save(): void
    {
        $draft = FlowDraft::factory()->create([
            'draft_version' => 1,
            'nodes'         => [],
        ]);

        app(SaveDraftService::class)->execute(
            $draft->flow_id,
            [
                'emit_1' => ['id' => 'emit_1', 'type' => 'emit_event', 'config' => ['event_type' => 'order.created']],
            ],
            [],
            null,
            expectedDraftVersion: 1,
        );

        // Visible tenant-wide even though the draft is not published yet.
        $this->assertDatabaseHas('tenant_events', [
            'tenant_id'  => $draft->tenant_id,
            'event_name' => 'order.created',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array{id: string, type: string, is_active: bool, priority: int, config: array<string, mixed>}
     */
    private function trigger(array $overrides = []): array
    {
        return array_merge([
            'id'        => (string) Str::uuid(),
            'type'      => 'message',
            'is_active' => true,
            'priority'  => 100,
            'config'    => ['keywords' => ['help'], 'phrases' => []],
        ], $overrides);
    }
}
