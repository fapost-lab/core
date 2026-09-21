<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Concurrency\LockHandle;
use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Concurrency\SessionLockManager;
use App\Domains\Flow\Concurrency\SessionLockRegistry;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\SessionLockLostException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * The engine ticks the session-lock heartbeat before every node
 * ({@see \App\Domains\Flow\Services\FlowEngine} `refreshSessionLock()`). A
 * false `extend()` means another worker's TTL take-over has already claimed
 * the slot — the engine must abandon execution rather than keep mutating
 * session state, per ADR Message Routing & Concurrency Control.
 */
final class FlowEngineSessionLockTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(NodeHandlerRegistry::class)->register(SessionLockTestHandler::class);
    }

    public function test_lost_session_lock_aborts_execution_before_the_node_runs(): void
    {
        $tenantId = (string) Str::uuid();

        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        // Fake Redis connection whose token-checked EXTEND script always
        // reports "no longer owner" — the same signal a TTL lapse + another
        // worker's take-over produces in production.
        $connection = $this->createMock(Connection::class);
        $connection->method('__call')->willReturn(0);
        $this->app->instance(SessionLockManager::class, new SessionLockManager($this->factoryFor($connection)));

        // Simulate the routing pipeline having already claimed the session
        // lock before handing execution to the engine.
        $scope = new LockScope($tenantId, (string) $contact->getKey(), (string) $assistant->getKey());
        $this->app->make(SessionLockRegistry::class)->set(new LockHandle($scope->key(), 'token-1', 30));

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Lock lost',
            'nodes'     => [
                ['id' => 'n1', 'type' => 'session_lock_test', 'version' => 1, 'config' => []],
            ],
            'edges'     => [],
            'is_active' => true,
        ]);

        $engine = $this->app->make(FlowEngineInterface::class);

        try {
            $engine->start($definition, $contact);
            $this->fail('Expected SessionLockLostException');
        } catch (SessionLockLostException) {
        }

        $session = FlowSession::query()->where('flow_definition_id', $definition->getKey())->sole();

        // The session was created and committed before the first node ran;
        // the heartbeat check aborts before the handler executes, so the
        // session is left Active rather than marked Failed.
        $this->assertSame(FlowSessionStatus::Active, $session->status);
        $this->assertSame('n1', $session->current_node_id);
        $this->assertDatabaseCount('flow_logs', 0);
    }

    private function factoryFor(Connection $connection): RedisFactory
    {
        return new class ($connection) implements RedisFactory {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function connection($name = null): Connection
            {
                return $this->connection;
            }
        };
    }
}

final class SessionLockTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'session_lock_test';
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
        return 'Session Lock Test';
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
        // Never reached: refreshSessionLock() throws before the handler runs.
        return NodeExecutionResult::failed('handler should not execute after lock loss');
    }
}
