<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Concurrency\SessionLockManager;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Jobs\Flow\StartFlowForContactJob;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;

/**
 * Real-Redis lock of {@see StartFlowForContactJob}: the start is decided under the contact's session lock, keyed by
 * (tenant, contact, the target flow's assistant), and a busy lock starts nothing and leaves the job to be retried.
 */
#[Group('redis')]
final class StartFlowForContactJobRedisTest extends FeatureTestCase
{
    use InteractsWithRedisLocks;

    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pingRedisOrFail();

        config([
            'flow.lock.acquisition_retries' => 1,
            'flow.lock.retry_delay_ms'      => 50,
        ]);
    }

    public function test_a_busy_lock_starts_nothing(): void
    {
        [$job, $contact, $scope] = $this->makeStart();

        $holder = $this->app->make(SessionLockManager::class)->acquire($scope, ttlSeconds: 30);
        $this->assertNotNull($holder, 'Precondition: another worker must hold the contact\'s session lock.');

        $this->expectException(SessionLockTimeoutException::class);

        try {
            $this->app->call([$job, 'handle']);
        } finally {
            $this->assertSame(0, FlowSession::query()->where('contact_id', $contact->getKey())->count());
            $this->assertSame($holder->token, $this->redisGet($scope->key()));
        }
    }

    public function test_the_start_runs_once_and_releases_the_lock(): void
    {
        [$job, $contact, $scope] = $this->makeStart();

        $this->app->call([$job, 'handle']);
        $this->app->forgetScopedInstances();
        $this->app->call([$job, 'handle']);

        $this->assertSame(1, FlowSession::query()->where('contact_id', $contact->getKey())->count());
        $this->assertNull($this->redisGet($scope->key()), 'The lock must be gone once the start completes.');
    }

    /**
     * @return array{0: StartFlowForContactJob, 1: Contact, 2: LockScope}
     */
    private function makeStart(): array
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));
        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);

        $flowId = (string) Str::uuid();
        FlowDraft::factory()->create(['tenant_id' => self::TENANT_ID, 'flow_id' => $flowId, 'assistant_id' => $assistant->getKey()]);
        FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flowId,
            'version'   => 1,
            'name'      => 'Redis start flow',
            'nodes'     => [['id' => 'n-end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'     => [],
            'is_active' => true,
        ]);

        $scope = new LockScope(self::TENANT_ID, (string) $contact->getKey(), (string) $assistant->getKey());
        $this->trackLockKey($scope->key());

        $job = new StartFlowForContactJob(self::TENANT_ID, $flowId, (string) $contact->getKey(), [], 'exec-1:start', 1, 30);

        return [$job, $contact, $scope];
    }
}
