<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

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
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;

/**
 * Real-Redis counterpart to tests/Feature/Domains/Flow/FlowEngineSessionLockTest.php.
 *
 * There the token mismatch is scripted on a mocked Redis connection; here a
 * second, real holder actually claims the key in Redis before the engine's
 * heartbeat tick runs, so the abort path is exercised end to end against the
 * real `EXTEND` Lua script.
 */
#[Group('redis')]
final class FlowEngineSessionLockRedisTest extends FeatureTestCase
{
    use InteractsWithRedisLocks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pingRedisOrFail();

        $this->app->make(NodeHandlerRegistry::class)->register(RedisFlowEngineLockTestHandler::class);
    }

    public function test_flow_engine_aborts_when_a_real_takeover_holder_claims_the_lock(): void
    {
        $tenantId = (string) Str::uuid();

        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create();

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        $scope = new LockScope($tenantId, (string) $contact->getKey(), (string) $assistant->getKey());
        $this->trackLockKey($scope->key());

        // Simulate the routing pipeline having claimed the session lock with
        // a token that will turn out to be stale by the time the engine ticks.
        $this->app->make(SessionLockRegistry::class)->set(new LockHandle($scope->key(), 'stale-token', 30));

        // A second, real worker actually takes over the same key before the
        // engine's heartbeat runs.
        $manager     = $this->app->make(SessionLockManager::class);
        $otherHolder = $manager->acquire($scope, ttlSeconds: 30);
        $this->assertNotNull($otherHolder, 'Precondition: the scope must be free for the takeover holder to claim it.');

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Redis lock lost',
            'nodes'     => [
                ['id' => 'n1', 'type' => 'redis_session_lock_test', 'version' => 1, 'config' => []],
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

        // Heartbeat aborts before the handler runs, so the session is left
        // Active on the first node rather than marked Failed.
        $this->assertSame(FlowSessionStatus::Active, $session->status);
        $this->assertSame('n1', $session->current_node_id);
        $this->assertDatabaseCount('flow_logs', 0);

        // The takeover holder's real Redis claim must be untouched.
        $this->assertSame($otherHolder->token, $this->redisGet($scope->key()));
    }
}

final class RedisFlowEngineLockTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'redis_session_lock_test';
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
        return 'Redis Session Lock Test';
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
        return NodeExecutionResult::failed('handler should not execute after a real lock takeover');
    }
}
