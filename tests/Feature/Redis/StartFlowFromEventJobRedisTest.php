<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Concurrency\SessionLockManager;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Jobs\Flow\StartFlowFromEventJob;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;

/**
 * Real-Redis counterpart to tests/Feature/Domains/Flow/Events/StartFlowFromEventJobTest.php:
 * the job now claims the contact's session lock — keyed by
 * ($contact->tenant_id, $contact->id, $assistantId), see
 * {@see StartFlowFromEventJob::handle()} — before starting the flow. The
 * engine itself is real here; only the lock's real-Redis behaviour is new.
 */
#[Group('redis')]
final class StartFlowFromEventJobRedisTest extends FeatureTestCase
{
    use InteractsWithRedisLocks;

    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pingRedisOrFail();

        // Keep the busy-lock case fast: the default budget is 3 * 2s.
        config([
            'flow.lock.acquisition_retries' => 1,
            'flow.lock.retry_delay_ms'      => 50,
        ]);
    }

    public function test_busy_lock_prevents_the_flow_from_starting(): void
    {
        [$assistant, $contact, $flowId, $scope] = $this->makeSubscribedFlow();

        $manager = $this->app->make(SessionLockManager::class);
        $holder  = $manager->acquire($scope, ttlSeconds: 30);
        $this->assertNotNull($holder, 'Precondition: another worker must hold the contact\'s session lock.');

        $job = new StartFlowFromEventJob(
            tenantId: self::TENANT_ID,
            flowId: $flowId,
            assistantId: (string) $assistant->getKey(),
            contactId: (string) $contact->getKey(),
            payload: [],
            eventName: 'order.created',
        );

        $this->expectException(SessionLockTimeoutException::class);

        try {
            app()->call([$job, 'handle']);
        } finally {
            $this->assertSame(
                0,
                FlowSession::query()->where('contact_id', $contact->getKey())->count(),
                'No session should be started while the contact\'s lock is busy.',
            );
            $this->assertSame($holder->token, $this->redisGet($scope->key()));
        }
    }

    public function test_happy_path_starts_the_flow_and_releases_the_lock(): void
    {
        [$assistant, $contact, $flowId, $scope] = $this->makeSubscribedFlow();

        $job = new StartFlowFromEventJob(
            tenantId: self::TENANT_ID,
            flowId: $flowId,
            assistantId: (string) $assistant->getKey(),
            contactId: (string) $contact->getKey(),
            payload: ['amount' => 42],
            eventName: 'order.created',
        );

        app()->call([$job, 'handle']);

        $session = FlowSession::query()
            ->where('contact_id', $contact->getKey())
            ->latest('created_at')
            ->first();

        $this->assertNotNull($session, 'The subscribed flow should have started a session.');
        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertSame(EndStatus::Success->value, $session->end_status);
        $this->assertNull($this->redisGet($scope->key()), 'The lock must be gone once the happy-path run completes.');
    }

    /**
     * @return array{0: Assistant, 1: Contact, 2: string, 3: LockScope}
     */
    private function makeSubscribedFlow(): array
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();

        $flowId = (string) Str::uuid();
        FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flowId,
            'version'   => 1,
            'name'      => 'Redis Event Flow',
            'nodes'     => [
                ['id' => 'n-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges'     => [],
            'is_active' => true,
        ]);

        $scope = new LockScope((string) $contact->tenant_id, (string) $contact->getKey(), (string) $assistant->getKey());
        $this->trackLockKey($scope->key());

        return [$assistant, $contact, $flowId, $scope];
    }
}
