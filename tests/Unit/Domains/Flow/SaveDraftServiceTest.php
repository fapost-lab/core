<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Exceptions\DraftVersionConflictException;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Services\SaveDraftService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
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
            $table->uuid('assistant_id')->nullable();
            $table->integer('draft_version')->default(1);
            $table->string('name');
            $table->json('nodes')->nullable();
            $table->json('edges')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('flow_triggers');
        Schema::create('flow_triggers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('assistant_id')->nullable();
            $table->uuid('flow_id')->unique();
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

    public function test_updates_nodes_and_returns_incremented_version(): void
    {
        $draft = FlowDraft::factory()->create([
            'draft_version' => 2,
            'nodes'         => [],
        ]);
        $service = app(SaveDraftService::class);

        $newVersion = $service->execute(
            $draft->flow_id,
            ['node_1' => ['id' => 'node_1', 'type' => 'input']],
            [],
            [
                'type'      => 'message',
                'is_active' => true,
                'priority'  => 100,
                'config'    => [
                    'keywords' => ['help'],
                    'phrases'  => [],
                ],
            ],
            expectedDraftVersion: 2,
        );

        $this->assertSame(3, $newVersion);
        $draft->refresh();
        $this->assertSame(3, $draft->draft_version);
        $this->assertIsArray($draft->nodes);
        $this->assertSame('input', $draft->nodes['node_1']['type']);
        $this->assertSame('message', FlowTrigger::query()->sole()->type->value);
    }

    public function test_it_keeps_existing_trigger_when_trigger_payload_is_null(): void
    {
        $draft = FlowDraft::factory()->create([
            'draft_version' => 2,
            'nodes'         => [],
        ]);

        FlowTrigger::query()->create([
            'tenant_id'    => $draft->tenant_id,
            'assistant_id' => $draft->assistant_id,
            'flow_id'      => $draft->flow_id,
            'type'         => 'message',
            'is_active'    => true,
            'priority'     => 100,
            'config'       => [
                'keywords' => ['help'],
                'phrases'  => [],
            ],
        ]);

        $service = app(SaveDraftService::class);

        $service->execute(
            $draft->flow_id,
            [],
            [],
            null,
            expectedDraftVersion: 2,
        );

        $this->assertDatabaseCount('flow_triggers', 1);
    }

    public function test_it_deletes_existing_trigger_only_when_delete_flag_is_explicit(): void
    {
        $draft = FlowDraft::factory()->create([
            'draft_version' => 2,
            'nodes'         => [],
        ]);

        FlowTrigger::query()->create([
            'tenant_id'    => $draft->tenant_id,
            'assistant_id' => $draft->assistant_id,
            'flow_id'      => $draft->flow_id,
            'type'         => 'message',
            'is_active'    => true,
            'priority'     => 100,
            'config'       => [
                'keywords' => ['help'],
                'phrases'  => [],
            ],
        ]);

        $service = app(SaveDraftService::class);

        $service->execute(
            $draft->flow_id,
            [],
            [],
            ['_delete' => true],
            expectedDraftVersion: 2,
        );

        $this->assertDatabaseCount('flow_triggers', 0);
    }

    public function test_it_preserves_runtime_schedule_fields_when_updating_existing_trigger(): void
    {
        $draft = FlowDraft::factory()->create([
            'draft_version' => 2,
            'nodes'         => [],
        ]);

        $trigger = FlowTrigger::query()->create([
            'tenant_id'    => $draft->tenant_id,
            'assistant_id' => $draft->assistant_id,
            'flow_id'      => $draft->flow_id,
            'type'         => 'schedule',
            'is_active'    => true,
            'priority'     => 100,
            'config'       => [
                'cron'     => '0 9 * * *',
                'timezone' => 'UTC',
            ],
            'last_run_at' => '2026-04-01 09:00:00',
            'next_run_at' => '2026-04-02 09:00:00',
        ]);

        $service = app(SaveDraftService::class);

        $service->execute(
            $draft->flow_id,
            [],
            [],
            [
                'type'      => 'schedule',
                'is_active' => true,
                'priority'  => 200,
                'config'    => [
                    'cron'     => '0 10 * * *',
                    'timezone' => 'UTC',
                ],
            ],
            expectedDraftVersion: 2,
        );

        $trigger->refresh();

        $this->assertSame(200, $trigger->priority);
        $this->assertSame('2026-04-01 09:00:00', $trigger->last_run_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-02 09:00:00', $trigger->next_run_at?->format('Y-m-d H:i:s'));
    }

    /**
     * SaveDraft no longer enforces tenant-event registry membership — that
     * check moved to {@see \App\Domains\Flow\Services\ValidateFlowService}
     * (covered by `test_validate_endpoint_reports_unknown_event_trigger`).
     * Drafts may reference an event before it appears in the registry; the
     * trigger row simply never fires at runtime until the event is declared.
     */
    public function test_it_persists_event_trigger_even_when_event_is_not_yet_registered(): void
    {
        $draft = FlowDraft::factory()->create([
            'draft_version' => 2,
            'nodes'         => [],
        ]);

        $service = app(SaveDraftService::class);

        $service->execute(
            $draft->flow_id,
            [],
            [],
            [
                'type'      => 'event',
                'is_active' => true,
                'priority'  => 100,
                'config'    => [
                    'event_name' => 'employee_registered',
                ],
            ],
            expectedDraftVersion: 2,
        );

        $this->assertDatabaseHas('flow_triggers', [
            'flow_id' => $draft->flow_id,
            'type'    => 'event',
        ]);
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
}
