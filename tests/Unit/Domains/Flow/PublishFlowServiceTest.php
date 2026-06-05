<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\PublishFlowService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

final class PublishFlowServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('media_file_references');
        Schema::dropIfExists('flow_definitions');
        Schema::dropIfExists('flow_drafts');

        Schema::create('media_file_references', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('media_file_id');
            $table->string('reference_type', 32);
            $table->uuid('reference_id');
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->index('media_file_id');
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('flow_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('assistant_id')->nullable();
            $table->uuid('flow_id')->unique();
            $table->integer('draft_version')->default(1);
            $table->string('name');
            $table->json('nodes')->nullable();
            $table->json('edges')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('logging_enabled')->default(false);
            $table->timestamps();
        });

        Schema::dropIfExists('tenant_variable_schema');
        Schema::create('tenant_variable_schema', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('storage', 16);
            $table->string('group', 64)->nullable();
            $table->string('name', 64);
            $table->string('type', 16);
            $table->uuid('declared_in_flow_id')->nullable();
            $table->string('declared_by_node_id', 128)->nullable();
            $table->timestampTz('updated_at');
            $table->unique(['storage', 'group', 'name'], 'tenant_variable_schema_unique');
        });

        Schema::dropIfExists('flow_callgraph_edges');
        Schema::create('flow_callgraph_edges', function (Blueprint $table): void {
            $table->uuid('caller_flow_id');
            $table->uuid('callee_flow_id');
            $table->uuid('caller_definition_id');
            $table->primary(['caller_flow_id', 'callee_flow_id', 'caller_definition_id']);
            $table->index('callee_flow_id');
            $table->index('caller_flow_id');
        });

        Schema::create('flow_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('flow_id');
            $table->unsignedInteger('version');
            $table->string('name');
            $table->json('nodes');
            $table->json('edges')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('logging_enabled')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['flow_id', 'version']);
        });

        $this->app->bind(DataAccessorRegistryInterface::class, static function (): DataAccessorRegistryInterface {
            return new class () implements DataAccessorRegistryInterface {
                public function has(string $namespacePrefix): bool
                {
                    return false;
                }

                public function resolve(string $namespacePrefix): \FAPost\Foundation\Contracts\DataAccessorInterface
                {
                    throw new LogicException('Not needed in this test.');
                }
            };
        });
    }

    public function test_publishes_logging_enabled_flag_from_draft_onto_new_definition(): void
    {
        $flowId = '00000000-0000-0000-0000-000000000777';

        FlowDraft::factory()->create([
            'flow_id'         => $flowId,
            'logging_enabled' => true,
            'nodes'           => [
                [
                    'id'      => 'start',
                    'type'    => 'input',
                    'version' => 1,
                    'config'  => [
                        'variable' => [
                            'name'    => 'answer',
                            'type'    => 'text',
                            'storage' => 'session',
                            'group'   => null,
                        ],
                    ],
                ],
            ],
        ]);

        $definition = app(PublishFlowService::class)->execute($flowId);

        $this->assertTrue((bool) $definition->logging_enabled);
    }

    public function test_publish_defaults_logging_enabled_false_when_draft_flag_unset(): void
    {
        $flowId = '00000000-0000-0000-0000-000000000888';

        FlowDraft::factory()->create([
            'flow_id' => $flowId,
            'nodes'   => [
                [
                    'id'      => 'start',
                    'type'    => 'input',
                    'version' => 1,
                    'config'  => [
                        'variable' => [
                            'name'    => 'answer',
                            'type'    => 'text',
                            'storage' => 'session',
                            'group'   => null,
                        ],
                    ],
                ],
            ],
        ]);

        $definition = app(PublishFlowService::class)->execute($flowId);

        $this->assertFalse((bool) $definition->logging_enabled);
    }

    public function test_assigns_unique_versions_for_repeated_publish_calls(): void
    {
        $flowId = '00000000-0000-0000-0000-000000000321';

        FlowDraft::factory()->create([
            'flow_id' => $flowId,
            'nodes'   => [
                [
                    'id'      => 'start',
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
                ],
            ],
        ]);

        $service = app(PublishFlowService::class);
        $first   = $service->execute($flowId);
        $second  = $service->execute($flowId);

        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertTrue($second->is_active);
        $this->assertFalse((bool) FlowDefinition::query()->findOrFail($first->id)->is_active);
    }

    public function test_populates_callgraph_edges_for_subflow_callees_on_publish(): void
    {
        $callerFlowId = '11111111-1111-1111-1111-111111111111';
        $calleeFlowId = '22222222-2222-2222-2222-222222222222';

        FlowDraft::factory()->create([
            'flow_id' => $callerFlowId,
            'nodes'   => [
                ['id' => 'sub-1', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $calleeFlowId, 'timeout' => 'PT1H']],
                ['id' => 'end-1', 'type' => 'end',     'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'sub-1', 'to' => 'end-1', 'handle' => 'success'],
            ],
        ]);

        $definition = app(PublishFlowService::class)->execute($callerFlowId);

        $edges = DB::table('flow_callgraph_edges')
            ->where('caller_definition_id', (string) $definition->getKey())
            ->get();

        $this->assertCount(1, $edges);
        $this->assertSame($callerFlowId, $edges[0]->caller_flow_id);
        $this->assertSame($calleeFlowId, $edges[0]->callee_flow_id);
    }

    public function test_replaces_callgraph_edges_when_a_flow_is_republished_with_different_callees(): void
    {
        $callerFlowId = '33333333-3333-3333-3333-333333333333';
        $oldCallee    = '44444444-4444-4444-4444-444444444444';
        $newCallee    = '55555555-5555-5555-5555-555555555555';

        $draft = FlowDraft::factory()->create([
            'flow_id' => $callerFlowId,
            'nodes'   => [
                ['id' => 'sub-1', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $oldCallee, 'timeout' => 'PT1H']],
                ['id' => 'end-1', 'type' => 'end',     'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'sub-1', 'to' => 'end-1', 'handle' => 'success'],
            ],
        ]);

        $service = app(PublishFlowService::class);
        $service->execute($callerFlowId);

        // Re-publish with a different callee.
        $draft->forceFill([
            'nodes' => [
                ['id' => 'sub-1', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $newCallee, 'timeout' => 'PT1H']],
                ['id' => 'end-1', 'type' => 'end',     'version' => 1, 'config' => ['status' => 'success']],
            ],
        ])->save();

        $secondDefinition = $service->execute($callerFlowId);

        // First definition's edges remain (different caller_definition_id —
        // each definition is independently indexed). The second definition
        // points at the new callee.
        $secondEdges = DB::table('flow_callgraph_edges')
            ->where('caller_definition_id', (string) $secondDefinition->getKey())
            ->pluck('callee_flow_id')
            ->all();

        $this->assertSame([$newCallee], $secondEdges);
    }

    public function test_rejects_publish_with_subflow_callee_belonging_to_different_assistant(): void
    {
        $callerFlowId = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        $calleeFlowId = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';

        FlowDraft::factory()->create([
            'flow_id'      => $callerFlowId,
            'assistant_id' => '11111111-1111-1111-1111-111111111111',
            'nodes'        => [
                ['id' => 'sub-1', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $calleeFlowId, 'timeout' => 'PT1H']],
                ['id' => 'end-1', 'type' => 'end',     'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'sub-1', 'to' => 'end-1', 'handle' => 'success'],
            ],
        ]);

        // Same-tenant callee, different assistant — must be rejected.
        FlowDraft::factory()->create([
            'flow_id'      => $calleeFlowId,
            'assistant_id' => '22222222-2222-2222-2222-222222222222',
            'nodes'        => [
                ['id' => 'end-1', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
        ]);

        try {
            app(PublishFlowService::class)->execute($callerFlowId);
            $this->fail('Expected FlowValidationException was not thrown.');
        } catch (FlowValidationException $exception) {
            $codes = array_map(static fn ($e): string => $e->code, $exception->errors);
            $this->assertContains('subflow_cross_assistant', $codes);
        }
    }

    public function test_allows_publish_with_subflow_callee_in_same_assistant(): void
    {
        $callerFlowId = 'cccccccc-cccc-cccc-cccc-cccccccccccc';
        $calleeFlowId = 'dddddddd-dddd-dddd-dddd-dddddddddddd';
        $assistantId  = '33333333-3333-3333-3333-333333333333';

        FlowDraft::factory()->create([
            'flow_id'      => $callerFlowId,
            'assistant_id' => $assistantId,
            'nodes'        => [
                ['id' => 'sub-1', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $calleeFlowId, 'timeout' => 'PT1H']],
                ['id' => 'end-1', 'type' => 'end',     'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'sub-1', 'to' => 'end-1', 'handle' => 'success'],
            ],
        ]);

        FlowDraft::factory()->create([
            'flow_id'      => $calleeFlowId,
            'assistant_id' => $assistantId,
            'nodes'        => [
                ['id' => 'end-1', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
        ]);

        $definition = app(PublishFlowService::class)->execute($callerFlowId);

        $this->assertSame(1, $definition->version);
    }

    public function test_publish_populates_variable_schema_table(): void
    {
        $flowId   = '00000000-0000-0000-0000-000000000901';
        $tenantId = '00000000-0000-0000-0000-000000000001';

        FlowDraft::factory()->create([
            'flow_id'   => $flowId,
            'tenant_id' => $tenantId,
            'nodes'     => [
                [
                    'id'      => 'inp-1',
                    'type'    => 'input',
                    'version' => 1,
                    'config'  => [
                        'variable' => [
                            'name'    => 'user_phone',
                            'storage' => 'session',
                            'type'    => 'phone',
                        ],
                    ],
                ],
            ],
        ]);

        app(PublishFlowService::class)->execute($flowId);

        $row = DB::table('tenant_variable_schema')
            ->where('name', 'user_phone')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('session', $row->storage);
        $this->assertSame('phone', $row->type);
        $this->assertNull($row->group);
    }

    public function test_publish_rejects_cross_flow_variable_type_conflict(): void
    {
        $flowIdA  = '00000000-0000-0000-0000-000000000902';
        $flowIdB  = '00000000-0000-0000-0000-000000000903';
        $tenantId = '00000000-0000-0000-0000-000000000001';

        // Flow A declares `answer` as `text`.
        FlowDraft::factory()->create([
            'flow_id'   => $flowIdA,
            'tenant_id' => $tenantId,
            'nodes'     => [
                [
                    'id'      => 'inp-a',
                    'type'    => 'input',
                    'version' => 1,
                    'config'  => [
                        'variable' => ['name' => 'answer', 'storage' => 'session', 'type' => 'text'],
                    ],
                ],
            ],
        ]);
        app(PublishFlowService::class)->execute($flowIdA);

        // Flow B declares `answer` as `number` — conflict.
        FlowDraft::factory()->create([
            'flow_id'   => $flowIdB,
            'tenant_id' => $tenantId,
            'nodes'     => [
                [
                    'id'      => 'inp-b',
                    'type'    => 'input',
                    'version' => 1,
                    'config'  => [
                        'variable' => ['name' => 'answer', 'storage' => 'session', 'type' => 'number'],
                    ],
                ],
            ],
        ]);

        $this->expectException(FlowValidationException::class);

        app(PublishFlowService::class)->execute($flowIdB);
    }

    public function test_throws_flow_validation_exception_when_draft_is_invalid(): void
    {
        $flowId = '00000000-0000-0000-0000-000000000654';

        FlowDraft::factory()->create([
            'flow_id' => $flowId,
            'nodes'   => [
                [
                    'id'      => 'condition_1',
                    'type'    => 'branch',
                    'version' => 1,
                    'config'  => ['check' => 'flow.answer'],
                    'outputs' => [
                        'yes' => ['next' => 'missing-node'],
                    ],
                ],
            ],
        ]);

        $service = app(PublishFlowService::class);

        try {
            $service->execute($flowId);
            $this->fail('Expected FlowValidationException was not thrown.');
        } catch (FlowValidationException $exception) {
            $this->assertNotEmpty($exception->errors);
            $this->assertContainsOnlyInstancesOf(FlowValidationErrorDto::class, $exception->errors);
        }
    }

    public function test_publish_registers_emit_event_names_into_tenant_wide_registry(): void
    {
        $this->createTenantEventsTable();

        $flowId = '99999999-9999-9999-9999-999999999999';

        $draft = FlowDraft::factory()->create([
            'flow_id' => $flowId,
            'nodes'   => [
                ['id' => 'emit-1', 'type' => 'emit_event', 'version' => 1, 'config' => ['event_type' => 'order.created']],
                ['id' => 'end-1',  'type' => 'end',        'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'emit-1', 'to' => 'end-1', 'handle' => 'default'],
            ],
        ]);

        app(PublishFlowService::class)->execute($flowId);

        $events = app(TenantEventRepositoryInterface::class)
            ->getEventNamesByTenant((string) $draft->tenant_id);

        $this->assertContains('order.created', $events);
    }

    public function test_event_registry_is_visible_across_assistants_of_the_same_tenant(): void
    {
        $this->createTenantEventsTable();

        $tenantId = '77777777-0000-0000-0000-000000000000';

        // Flow owned by assistant A emits the event.
        $emitterFlowId = '77777777-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
        FlowDraft::factory()->create([
            'flow_id'      => $emitterFlowId,
            'tenant_id'    => $tenantId,
            'assistant_id' => 'aaaaaaaa-0000-0000-0000-000000000000',
            'nodes'        => [
                ['id' => 'emit-1', 'type' => 'emit_event', 'version' => 1, 'config' => ['event_type' => 'lead.qualified']],
                ['id' => 'end-1',  'type' => 'end',        'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges' => [['id' => 'e1', 'from' => 'emit-1', 'to' => 'end-1', 'handle' => 'default']],
        ]);

        app(PublishFlowService::class)->execute($emitterFlowId);

        // The event is visible tenant-wide (the registry is not scoped to an assistant).
        $events = app(TenantEventRepositoryInterface::class)->getEventNamesByTenant($tenantId);

        $this->assertContains('lead.qualified', $events);
    }

    private function createTenantEventsTable(): void
    {
        Schema::dropIfExists('tenant_events');
        Schema::create('tenant_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('event_name', 120);
            $table->timestamps();
            $table->unique(['tenant_id', 'event_name']);
        });
    }
}
