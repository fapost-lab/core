<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowExecutionLimitExceededException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use FAPost\Foundation\Contracts\NodeHandlerInterface;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\IncomingMessageType;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

final class FlowEngineTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->app->make(NodeHandlerRegistry::class);
        $registry->register(new SequentialFlowTestHandler());
        $registry->register(new WaitingFlowTestHandler());
        $registry->register(new InfiniteLoopFlowTestHandler());
    }

    public function test_start_executes_linear_flow_until_finished(): void
    {
        $tenantId = (string) Str::uuid();

        $assistant = Assistant::factory()->create([
            'tenant_id' => $tenantId,
        ]);

        $contact = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Linear',
            'nodes'     => [
                ['id' => 'n1', 'type' => 'sequential_test', 'version' => 1, 'config' => []],
                ['id' => 'n2', 'type' => 'sequential_test', 'version' => 1, 'config' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source_node_id' => 'n1', 'target_node_id' => 'n2', 'transition' => 'default'],
            ],
            'is_active' => true,
        ]);

        $engine = $this->app->make(FlowEngineInterface::class);

        $session = $engine->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::Completed, $session->status);
        $this->assertNull($session->current_node_id);

        $logs = DB::table('flow_logs')->where('session_id', $session->getKey())->orderBy('created_at')->get();

        $this->assertCount(4, $logs);
        $this->assertSame('flow_start', $logs[0]->status);
        $this->assertSame('n1', $logs[1]->node_id);
        $this->assertSame('n2', $logs[2]->node_id);
        $this->assertSame('finished', $logs[2]->status);
        $this->assertSame('flow_end', $logs[3]->status);
        $this->assertSame('finished', json_decode((string) $logs[3]->metadata, true)['reason'] ?? null);
    }

    public function test_resume_passes_incoming_message_only_for_first_node_in_run(): void
    {
        $tenantId = (string) Str::uuid();

        $assistant = Assistant::factory()->create([
            'tenant_id' => $tenantId,
        ]);

        $contact = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Wait',
            'nodes'     => [
                ['id' => 'w1', 'type' => 'waiting_test', 'version' => 1, 'config' => []],
            ],
            'edges'     => [],
            'is_active' => true,
        ]);

        $engine = $this->app->make(FlowEngineInterface::class);

        $session = $engine->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::WaitingInput, $session->status);

        $message = new IncomingMessage(
            updateId: 'u1',
            externalUserId: 'ext',
            externalChatId: 'chat',
            text: 'hello',
            type: IncomingMessageType::Text,
            platform: 'telegram',
        );

        $session = $engine->resume($session, $message);

        $this->assertSame(FlowSessionStatus::Completed, $session->status);
    }

    public function test_max_iterations_fails_session_and_throws(): void
    {
        Config::set('flow.execution.max_iterations', 4);

        $tenantId = (string) Str::uuid();

        $assistant = Assistant::factory()->create([
            'tenant_id' => $tenantId,
        ]);

        $contact = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Loop',
            'nodes'     => [
                ['id' => 'n0', 'type' => 'infinite_loop_test', 'version' => 1, 'config' => []],
                ['id' => 'n1', 'type' => 'infinite_loop_test', 'version' => 1, 'config' => []],
            ],
            'edges' => [
                ['id' => 'e0', 'source_node_id' => 'n0', 'target_node_id' => 'n1', 'transition' => 'next'],
                ['id' => 'e1', 'source_node_id' => 'n1', 'target_node_id' => 'n1', 'transition' => 'default'],
            ],
            'is_active' => true,
        ]);

        $engine = $this->app->make(FlowEngineInterface::class);

        try {
            $engine->start($definition, $contact);
            $this->fail('Expected FlowExecutionLimitExceededException');
        } catch (FlowExecutionLimitExceededException) {
        }

        $session = FlowSession::query()->where('flow_definition_id', $definition->getKey())->sole();

        $this->assertSame(FlowSessionStatus::Failed, $session->status);

        $endLog = DB::table('flow_logs')->where('session_id', $session->getKey())->where('status', 'flow_end')->latest('created_at')->first();

        $this->assertSame('max_iterations_exceeded', json_decode((string) $endLog->metadata, true)['reason'] ?? null);
    }
}

final class SequentialFlowTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'sequential_test';
    }

    public function version(): int
    {
        return 1;
    }

    /**
     * @return list<int>
     */
    public function supportedVersions(): array
    {
        return [1];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $id = $nodeConfig['id'] ?? '';

        return match ($id) {
            'n1'    => NodeExecutionResult::completed(),
            'n2'    => NodeExecutionResult::finished(),
            default => NodeExecutionResult::failed('unexpected node'),
        };
    }
}

final class WaitingFlowTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'waiting_test';
    }

    public function version(): int
    {
        return 1;
    }

    /**
     * @return list<int>
     */
    public function supportedVersions(): array
    {
        return [1];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        if (null === $context->incoming) {
            return NodeExecutionResult::waiting();
        }

        return NodeExecutionResult::completed();
    }
}

final class InfiniteLoopFlowTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'infinite_loop_test';
    }

    public function version(): int
    {
        return 1;
    }

    /**
     * @return list<int>
     */
    public function supportedVersions(): array
    {
        return [1];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return match ($nodeConfig['id'] ?? '') {
            'n0'    => NodeExecutionResult::completed('next'),
            default => NodeExecutionResult::completed(),
        };
    }
}
