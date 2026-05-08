<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\PublishFlowService;
use Illuminate\Database\Schema\Blueprint;
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
            $table->timestamps();
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
                    'config'  => [],
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

        $edges = \Illuminate\Support\Facades\DB::table('flow_callgraph_edges')
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
        $secondEdges = \Illuminate\Support\Facades\DB::table('flow_callgraph_edges')
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
}
