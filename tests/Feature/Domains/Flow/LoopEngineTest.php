<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Integration tests for loop / loop_end nodes executing through the full FlowEngine.
 */
final class LoopEngineTest extends FeatureTestCase
{
    public function test_counted_loop_executes_body_n_times_and_completes(): void
    {
        $tenantId  = (string) Str::uuid();
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        // Flow: loop(3) → body_node → loop_end → (back to loop) → after_loop → end
        $definition = FlowDefinition::query()->create([
            'tenant_id'       => $tenantId,
            'flow_id'         => (string) Str::uuid(),
            'version'         => 1,
            'name'            => 'Counted Loop',
            'is_active'       => true,
            'logging_enabled' => false,
            'nodes'           => [
                [
                    'id'      => 'loop',
                    'type'    => 'loop',
                    'version' => 1,
                    'config'  => [
                        'mode'          => 'counted',
                        'iterator_name' => 'iterator',
                        'count_source'  => ['type' => 'literal', 'value' => 3],
                    ],
                ],
                [
                    'id'      => 'body',
                    'type'    => 'loop_end',
                    'version' => 1,
                    'config'  => [
                        'loop_node_id'  => 'loop',
                        'iterator_name' => 'iterator',
                    ],
                ],
                [
                    'id'      => 'done',
                    'type'    => 'end',
                    'version' => 1,
                    'config'  => ['status' => 'success'],
                ],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'loop', 'to' => 'body', 'handle' => 'loop'],
                ['id' => 'e2', 'from' => 'loop', 'to' => 'done', 'handle' => 'default'],
            ],
        ]);

        $engine  = $this->app->make(FlowEngineInterface::class);
        $session = $engine->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::Ended, $session->status);

        // Iterator keys should be absent or explicitly null after cleanup.
        $state = $session->state;
        $this->assertNull($state['flow']['iterator'] ?? null);
        $this->assertNull($state['flow']['iterator_total'] ?? null);
    }

    public function test_counted_loop_body_executes_exactly_n_times(): void
    {
        $tenantId  = (string) Str::uuid();
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        // Compact loop: loop(2) → loop_end → (back) → end
        $definition = FlowDefinition::query()->create([
            'tenant_id'       => $tenantId,
            'flow_id'         => (string) Str::uuid(),
            'version'         => 1,
            'name'            => 'Compact Counted Loop',
            'is_active'       => true,
            'logging_enabled' => false,
            'nodes'           => [
                [
                    'id'      => 'loop',
                    'type'    => 'loop',
                    'version' => 1,
                    'config'  => [
                        'mode'          => 'counted',
                        'iterator_name' => 'i',
                        'count_source'  => ['type' => 'literal', 'value' => 2],
                    ],
                ],
                [
                    'id'      => 'le',
                    'type'    => 'loop_end',
                    'version' => 1,
                    'config'  => [
                        'loop_node_id'  => 'loop',
                        'iterator_name' => 'i',
                    ],
                ],
                [
                    'id'      => 'end',
                    'type'    => 'end',
                    'version' => 1,
                    'config'  => ['status' => 'success'],
                ],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'loop', 'to' => 'le', 'handle' => 'loop'],
                ['id' => 'e2', 'from' => 'loop', 'to' => 'end', 'handle' => 'default'],
            ],
        ]);

        $engine  = $this->app->make(FlowEngineInterface::class);
        $session = $engine->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::Ended, $session->status);
    }

    public function test_while_loop_exits_immediately_when_condition_initially_false(): void
    {
        $tenantId  = (string) Str::uuid();
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        // While loop condition checks flow.flag == 'yes' but state has 'no'.
        $definition = FlowDefinition::query()->create([
            'tenant_id'       => $tenantId,
            'flow_id'         => (string) Str::uuid(),
            'version'         => 1,
            'name'            => 'While Loop Immediate Exit',
            'is_active'       => true,
            'logging_enabled' => false,
            'nodes'           => [
                [
                    'id'      => 'loop',
                    'type'    => 'loop',
                    'version' => 1,
                    'config'  => [
                        'mode'          => 'while',
                        'iterator_name' => 'iterator',
                        'condition'     => [
                            'left'     => 'flow.flag',
                            'operator' => 'eq',
                            'value'    => 'yes',
                        ],
                    ],
                ],
                [
                    'id'      => 'le',
                    'type'    => 'loop_end',
                    'version' => 1,
                    'config'  => [
                        'loop_node_id'  => 'loop',
                        'iterator_name' => 'iterator',
                    ],
                ],
                [
                    'id'      => 'end',
                    'type'    => 'end',
                    'version' => 1,
                    'config'  => ['status' => 'success'],
                ],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'loop', 'to' => 'le', 'handle' => 'loop'],
                ['id' => 'e2', 'from' => 'loop', 'to' => 'end', 'handle' => 'default'],
            ],
        ]);

        // flow.flag is not set in state so eq 'yes' is false on first check → loop exits immediately.
        $engine  = $this->app->make(FlowEngineInterface::class);
        $session = $engine->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::Ended, $session->status);
    }

    public function test_loop_iterator_null_after_exit(): void
    {
        $tenantId  = (string) Str::uuid();
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $definition = FlowDefinition::query()->create([
            'tenant_id'       => $tenantId,
            'flow_id'         => (string) Str::uuid(),
            'version'         => 1,
            'name'            => 'Iterator Cleanup',
            'is_active'       => true,
            'logging_enabled' => false,
            'nodes'           => [
                [
                    'id'      => 'loop',
                    'type'    => 'loop',
                    'version' => 1,
                    'config'  => [
                        'mode'         => 'counted',
                        'count_source' => ['type' => 'literal', 'value' => 1],
                    ],
                ],
                [
                    'id'      => 'le',
                    'type'    => 'loop_end',
                    'version' => 1,
                    'config'  => [
                        'loop_node_id'  => 'loop',
                        'iterator_name' => 'iterator',
                    ],
                ],
                [
                    'id'      => 'end',
                    'type'    => 'end',
                    'version' => 1,
                    'config'  => ['status' => 'success'],
                ],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'loop', 'to' => 'le', 'handle' => 'loop'],
                ['id' => 'e2', 'from' => 'loop', 'to' => 'end', 'handle' => 'default'],
            ],
        ]);

        $engine  = $this->app->make(FlowEngineInterface::class);
        $session = $engine->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::Ended, $session->status);

        // After loop exit the iterator key should be absent or null.
        $state = $session->state;
        $this->assertNull($state['flow']['iterator'] ?? null);
    }

    public function test_a_call_node_in_a_loop_sends_a_different_idempotency_key_on_each_pass(): void
    {
        Http::fake(['api.example.com/*' => Http::response([], 200)]);

        $tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $tenantId, schemaName: 'main'));

        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $definition = FlowDefinition::query()->create([
            'tenant_id'       => $tenantId,
            'flow_id'         => (string) Str::uuid(),
            'version'         => 1,
            'name'            => 'Call In Loop',
            'is_active'       => true,
            'logging_enabled' => false,
            'nodes'           => [
                [
                    'id'      => 'loop',
                    'type'    => 'loop',
                    'version' => 1,
                    'config'  => [
                        'mode'          => 'counted',
                        'iterator_name' => 'iterator',
                        'count_source'  => ['type' => 'literal', 'value' => 3],
                    ],
                ],
                [
                    'id'      => 'call',
                    'type'    => 'call',
                    'version' => 1,
                    'config'  => ['transport' => 'http', 'target' => 'POST https://api.example.com/hook'],
                ],
                [
                    'id'      => 'body',
                    'type'    => 'loop_end',
                    'version' => 1,
                    'config'  => ['loop_node_id' => 'loop', 'iterator_name' => 'iterator'],
                ],
                ['id' => 'done', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'loop', 'to' => 'call', 'handle' => 'loop'],
                ['id' => 'e2', 'from' => 'call', 'to' => 'body', 'handle' => 'success'],
                ['id' => 'e3', 'from' => 'call', 'to' => 'body', 'handle' => 'error'],
                ['id' => 'e4', 'from' => 'loop', 'to' => 'done', 'handle' => 'default'],
            ],
        ]);

        $session = $this->app->make(FlowEngineInterface::class)->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::Ended, $session->status);

        $keys = Http::recorded()->map(static fn (array $pair): string => $pair[0]->header('Idempotency-Key')[0])->all();

        $this->assertCount(3, $keys);
        $this->assertCount(3, array_unique($keys));
    }
}
