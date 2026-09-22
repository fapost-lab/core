<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Contracts\MutableDataAccessorRegistryInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\FlowExecutionLimitExceededException;
use App\Domains\Flow\Logging\Contracts\FlowLogPartitionManagerInterface;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use Fapost\Foundation\Contracts\DataAccessorInterface;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

final class FlowEngineTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $registry = $this->app->make(NodeHandlerRegistry::class);
        $registry->register(SequentialFlowTestHandler::class);
        $registry->register(WaitingFlowTestHandler::class);
        $registry->register(DelayedFlowTestHandler::class);
        $registry->register(InfiniteLoopFlowTestHandler::class);
        $registry->register(SetLanguageEffectTestHandler::class);
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
                ['id' => 'e1', 'from' => 'n1', 'to' => 'n2', 'handle' => 'default'],
            ],
            'is_active' => true,
        ]);

        $engine = $this->app->make(FlowEngineInterface::class);

        $session = $engine->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::Completed, $session->status);
        $this->assertNull($session->current_node_id);

        $logs = DB::table('flow_logs')->where('session_id', $session->getKey())->orderBy('created_at')->get();

        $this->assertCount(2, $logs);
        $this->assertSame('n1', $logs[0]->node_id);
        $this->assertSame('executed', $logs[0]->status);
        $this->assertSame('n2', $logs[1]->node_id);
        $this->assertSame('terminal', $logs[1]->status);
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

    public function test_delayed_node_parks_like_waiting_so_the_next_message_resumes_it(): void
    {
        $tenantId  = (string) Str::uuid();
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();
        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Delayed',
            'nodes'     => [
                ['id' => 'd1', 'type' => 'delayed_test', 'version' => 1, 'config' => []],
            ],
            'edges'     => [],
            'is_active' => true,
        ]);

        $engine  = $this->app->make(FlowEngineInterface::class);
        $session = $engine->start($definition, $contact);

        $this->assertSame(FlowSessionStatus::WaitingInput, $session->status);
        $this->assertSame(
            (string) $session->getKey(),
            (string) $this->app->make(FlowSessionRepositoryInterface::class)
                ->findActiveForContact($contact, (string) $assistant->getKey())?->getKey(),
            'A delayed session must stay findable, or the next message starts the default flow instead.',
        );

        $session = $engine->resume($session, new IncomingMessage(
            updateId: 'u-delayed',
            externalUserId: 'ext',
            externalChatId: 'chat',
            text: 'hello',
            type: IncomingMessageType::Text,
            platform: 'telegram',
        ));

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
                ['id' => 'e0', 'from' => 'n0', 'to' => 'n1', 'handle' => 'next'],
                ['id' => 'e1', 'from' => 'n1', 'to' => 'n1', 'handle' => 'default'],
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

        $this->assertDatabaseCount('flow_logs', 4);
    }

    public function test_condition_logs_resolved_runtime_value_expression_and_transition(): void
    {
        $tenantId = (string) Str::uuid();

        $assistant = Assistant::factory()->create([
            'tenant_id' => $tenantId,
        ]);

        $contact = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);
        $this->registerModuleAccessorOnce('module.hr', new StaticHrDataAccessor());

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Condition Audit',
            'nodes'     => [
                [
                    'id'      => 'c1',
                    'type'    => 'branch',
                    'version' => 1,
                    'config'  => [
                        'check' => 'module.hr.department',
                        'rules' => [
                            ['operator' => 'eq', 'value' => 'logistics', 'handle' => 'match'],
                        ],
                    ],
                ],
                ['id' => 'n2', 'type' => 'sequential_test', 'version' => 1, 'config' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'c1', 'to' => 'n2', 'handle' => 'match'],
            ],
            'is_active' => true,
        ]);

        $engine = $this->app->make(FlowEngineInterface::class);
        $engine->start($definition, $contact);

        $conditionLog = DB::table('flow_logs')
            ->where('node_id', 'c1')
            ->where('node_type', 'branch')
            ->first();

        $this->assertNotNull($conditionLog);

        $resolved = json_decode((string) $conditionLog->resolved, true);
        $this->assertSame('logistics', $resolved['module.hr.department'] ?? null);
    }

    public function test_set_contact_language_effect_updates_contact_language(): void
    {
        $tenantId = (string) Str::uuid();

        $assistant = Assistant::factory()->create([
            'tenant_id' => $tenantId,
        ]);

        $contact = Contact::factory()->forTenant($tenantId)->create([
            'language' => 'en',
        ]);

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Set language',
            'nodes'     => [
                ['id' => 'lang-1', 'type' => 'set_language_effect_test', 'version' => 1, 'config' => []],
            ],
            'edges'     => [],
            'is_active' => true,
        ]);

        $engine = $this->app->make(FlowEngineInterface::class);
        $engine->start($definition, $contact);

        $this->assertSame('es', $contact->fresh()->language);
    }

    /**
     * flow_logs is partitioned by month and the migration only seeds three of
     * them, so a schema older than that runs into a missing partition. Before
     * the writer created partitions on demand, that insert failed inside the
     * persistence transaction, failed the queue job, and every retry re-ran the
     * flow — re-sending the user every message the flow had already delivered.
     */
    public function test_flow_log_partition_is_created_on_demand(): void
    {
        if ('pgsql' !== DB::connection()->getDriverName()) {
            $this->markTestSkipped('flow_logs is only partitioned on Postgres.');
        }

        DB::statement('DROP TABLE IF EXISTS flow_logs_' . now()->format('Y_m'));

        $session = $this->startLinearFlow();

        $this->assertSame(FlowSessionStatus::Completed, $session->status);
        $this->assertSame(1, DB::table('flow_logs')->where('session_id', $session->getKey())->count());
    }

    public function test_broken_flow_log_write_does_not_abort_execution(): void
    {
        $this->mock(
            FlowLogPartitionManagerInterface::class,
            function (MockInterface $mock): void {
                $mock->shouldReceive('ensureMonthlyPartition')->andThrow(new RuntimeException('log storage is down'));
            },
        );

        $session = $this->startLinearFlow();

        // Telemetry died, the flow did not: the session is still completed, so
        // the job succeeds and nothing gets re-sent on a retry.
        $this->assertSame(FlowSessionStatus::Completed, $session->status);
        $this->assertNull($session->current_node_id);
    }

    public function test_start_seeds_the_channel_identity_into_system_state(): void
    {
        $tenantId = (string) Str::uuid();

        $assistant = Assistant::factory()->create([
            'tenant_id' => $tenantId,
        ]);

        $contact = Contact::factory()->forTenant($tenantId)->create();

        $channel = Channel::withoutEvents(function () use ($assistant, $tenantId): Channel {
            $channel = Channel::factory()->create([
                'assistant_id' => $assistant->getKey(),
                'tenant_id'    => $tenantId,
                'type'         => ChannelTypeEnum::Telegram,
                'is_active'    => true,
            ]);

            $channel->forceFill(['telegram_bot_username' => 'engine_demo_bot'])->save();

            return $channel;
        });

        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Channel identity',
            'nodes'     => [
                ['id' => 'n1', 'type' => 'waiting_test', 'version' => 1, 'config' => []],
            ],
            'edges'     => [],
            'is_active' => true,
        ]);

        $session = $this->app->make(FlowEngineInterface::class)->start($definition, $contact);

        $this->assertSame('engine_demo_bot', $session->state['system']['channel']['bot_username']);
        $this->assertSame('@engine_demo_bot', $session->state['system']['channel']['bot_handle']);
        $this->assertSame('https://t.me/engine_demo_bot', $session->state['system']['channel']['link']);
    }

    /**
     * Single-node flow executed to completion — the smallest run that produces
     * both a session and a flow-log entry.
     */
    private function startLinearFlow(): FlowSession
    {
        $tenantId  = (string) Str::uuid();
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Linear',
            'nodes'     => [
                ['id' => 'n1', 'type' => 'sequential_test', 'version' => 1, 'config' => []],
            ],
            'edges'     => [],
            'is_active' => true,
        ]);

        return $this->app->make(FlowEngineInterface::class)->start($definition, $contact);
    }

    private function registerModuleAccessorOnce(string $prefix, DataAccessorInterface $accessor): void
    {
        $readRegistry = $this->app->make(DataAccessorRegistryInterface::class);

        if (! $readRegistry->has($prefix)) {
            $this->app->make(MutableDataAccessorRegistryInterface::class)->register($prefix, $accessor);
        }
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

    public function label(): string
    {
        return 'Sequential Test';
    }

    public function category(): string
    {
        return 'Test';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $id = $nodeConfig['id'] ?? '';

        return match ($id) {
            'n1'    => NodeExecutionResult::executed(),
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

    public function label(): string
    {
        return 'Waiting Test';
    }

    public function category(): string
    {
        return 'Test';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        if (null === $context->incoming) {
            return NodeExecutionResult::waiting();
        }

        return NodeExecutionResult::executed();
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

    public function label(): string
    {
        return 'Infinite Loop Test';
    }

    public function category(): string
    {
        return 'Test';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return match ($nodeConfig['id'] ?? '') {
            'n0'    => NodeExecutionResult::executed('next'),
            default => NodeExecutionResult::executed(),
        };
    }
}

final class StaticHrDataAccessor implements DataAccessorInterface
{
    public function namespace(): string
    {
        return 'hr';
    }

    public function get(string $key, string $contactId, string $tenantId): mixed
    {
        return match ($key) {
            'department' => 'logistics',
            default      => null,
        };
    }

    /**
     * @return string[]
     */
    public function supportedKeys(): array
    {
        return ['department'];
    }
}

final class SetLanguageEffectTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'set_language_effect_test';
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

    public function label(): string
    {
        return 'Set Language Effect Test';
    }

    public function category(): string
    {
        return 'Test';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $context->contactWriter?->write('contact.language', 'es');

        return NodeExecutionResult::executed();
    }
}

final class DelayedFlowTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'delayed_test';
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

    public function label(): string
    {
        return 'Delayed Test';
    }

    public function category(): string
    {
        return 'Test';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        if (null === $context->incoming) {
            return NodeExecutionResult::delayed();
        }

        return NodeExecutionResult::executed();
    }
}
