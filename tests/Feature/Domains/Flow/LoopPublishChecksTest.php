<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * The loop checks are wired into the container-built validator, so validate reports them and publish refuses the
 * errors; the while-condition advice is a warning that never blocks publishing.
 */
final class LoopPublishChecksTest extends FeatureTestCase
{
    private string $tenantId;

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $this->tenantId, schemaName: 'main'));

        $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId, 'default_language' => 'en']);
        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);
    }

    public function test_publish_refuses_an_iterator_that_shadows_a_session_variable(): void
    {
        $draft = $this->draft(
            $this->loopFlow(
                ['mode' => 'counted', 'count_source' => ['type' => 'literal', 'value' => 3]],
                ['type' => 'assign', 'config' => ['operations' => [['variable' => ['name' => 'iterator', 'storage' => 'session'], 'value' => '1']]]],
            ),
        );

        try {
            $this->app->make(PublishFlowService::class)->execute($draft->flow_id);
            $this->fail('The publish should be refused.');
        } catch (FlowValidationException $exception) {
            $codes = array_map(static fn ($error): string => $error->code, $exception->errors);

            $this->assertSame(['loop_iterator_variable_conflict'], $codes);
            $this->assertSame('nodes.loop.config.iterator_name', $exception->errors[0]->path);
        }
    }

    public function test_publish_refuses_a_non_numeric_literal_count(): void
    {
        $draft = $this->draft($this->loopFlow(['mode' => 'counted', 'count_source' => ['type' => 'literal', 'value' => 'many']]));

        try {
            $this->app->make(PublishFlowService::class)->execute($draft->flow_id);
            $this->fail('The publish should be refused.');
        } catch (FlowValidationException $exception) {
            $this->assertSame(['loop_count_not_numeric'], array_map(static fn ($error): string => $error->code, $exception->errors));
        }
    }

    public function test_an_unchanged_while_condition_is_a_warning_and_the_flow_still_publishes(): void
    {
        $config = ['mode' => 'while', 'condition' => [
            'left'     => ['ref' => 'user_variable', 'variable' => ['name' => 'done', 'storage' => 'session']],
            'operator' => 'eq',
            'value'    => false,
        ]];
        $draft = $this->draft($this->loopFlow($config));

        $result = $this->app->make(ValidateFlowService::class)->execute(
            nodes: $draft->nodes,
            flowId: $draft->flow_id,
            edges: $draft->edges,
        );

        $this->assertTrue($result->valid);
        $this->assertSame(['loop_while_condition_unchanged'], array_map(static fn ($warning): string => $warning->code, $result->warnings));
        $this->assertSame('nodes.loop.config.condition', $result->warnings[0]->path);

        $definition = $this->app->make(PublishFlowService::class)->execute($draft->flow_id);

        $this->assertSame(1, $definition->version);
    }

    /**
     * A loop around one body node, ending in `end`: loop -(loop)-> body -> loop_end, loop -(default)-> end.
     *
     * @param  array<string, mixed>       $loopConfig
     * @param  array<string, mixed>|null  $body       the body node's type and config; a `assign` that sets nothing by default
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function loopFlow(array $loopConfig, ?array $body = null): array
    {
        $body ??= ['type' => 'assign', 'config' => ['operations' => [['variable' => ['name' => 'tick', 'storage' => 'session'], 'value' => '1']]]];

        return [
            'nodes' => [
                ['id' => 'loop', 'type' => 'loop', 'version' => 1, 'config' => $loopConfig],
                ['id' => 'body', 'type' => $body['type'], 'version' => 1, 'config' => $body['config']],
                ['id' => 'loop_end', 'type' => 'loop_end', 'version' => 1, 'config' => ['loop_node_id' => 'loop']],
                ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'loop', 'to' => 'body', 'handle' => 'loop'],
                ['id' => 'e2', 'from' => 'body', 'to' => 'loop_end', 'handle' => 'default'],
                ['id' => 'e3', 'from' => 'loop', 'to' => 'end', 'handle' => 'default'],
            ],
        ];
    }

    /**
     * @param  array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}  $flow
     */
    private function draft(array $flow): FlowDraft
    {
        return FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => $flow['nodes'],
            'edges'        => $flow['edges'],
        ]);
    }
}
