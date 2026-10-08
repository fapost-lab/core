<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Jobs\RunBroadcastJob;
use App\Domains\Broadcasting\Jobs\SendBroadcastRecipientJob;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Jobs\SendContactNotificationJob;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Staff\Jobs\SendStaffNotificationJob;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Webhook\Jobs\IncomingMessageJob;
use App\Jobs\Flow\DispatchFlowTriggerEventJob;
use App\Jobs\Flow\ResumeDelayedFlowSessionJob;
use App\Jobs\Flow\ResumeTimedOutSendMessageNodeJob;
use App\Jobs\Flow\StartFlowFromEventJob;
use App\Jobs\Media\CleanupSoftDeletedMediaJob;
use App\Jobs\Messaging\BroadcastSendJob;
use Fapost\Foundation\DTO\InboundWebhookPayload;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\MessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Fapost\Foundation\Tenancy\DTO\TenantAccessState;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantAccessMode;

/**
 * A stopped tenant on the background side: inbound work and flow-triggered work is dropped,
 * scheduled work waits and runs once the tenant is active again, housekeeping carries on.
 */
final class StoppedTenantRuntimeTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private FakeTenantAccessMode $mode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mode = FakeTenantAccessMode::stopped();
        $this->app->instance(TenantAccessModeInterface::class, $this->mode);
    }

    public function test_an_inbound_message_creates_no_contact_and_the_job_finishes(): void
    {
        Log::spy();

        IncomingMessageJob::dispatchSync(new InboundWebhookPayload(
            tenantId: self::TENANT_ID,
            schema: 'main',
            assistantId: (string) Assistant::factory()->create(['tenant_id' => self::TENANT_ID])->getKey(),
            channelId: 'channel-1',
            platform: 'telegram',
            rawPayload: ['update_id' => 1, 'message' => ['from' => ['id' => 42], 'chat' => ['id' => 42], 'text' => 'hi']],
            idempotencyKey: 'tg:channel-1:up-1',
            receivedAt: 1_700_000_000,
        ));

        $this->assertSame(0, Contact::query()->count());
        $this->assertSame(0, DB::table('flow_sessions')->count());
        Log::shouldHaveReceived('info')->with('tenant.access_mode.job_dropped', [
            'job'       => IncomingMessageJob::class,
            'tenant_id' => self::TENANT_ID,
        ])->once();
    }

    /**
     * @return array<string, array{0: class-string, 1: callable(): object}>
     */
    public static function droppedJobs(): array
    {
        $tenant = self::TENANT_ID;

        return [
            'event dispatch'       => [DispatchFlowTriggerEventJob::class, static fn (): object => new DispatchFlowTriggerEventJob($tenant, 'employee_registered', [], [])],
            'flow start by event'  => [StartFlowFromEventJob::class, static fn (): object => new StartFlowFromEventJob($tenant, 'flow-1', 'assistant-1', 'contact-1', [], 'employee_registered')],
            'broadcast recipient'  => [SendBroadcastRecipientJob::class, static fn (): object => new SendBroadcastRecipientJob($tenant, 'recipient-1')],
            'contact notification' => [SendContactNotificationJob::class, static fn (): object => new SendContactNotificationJob($tenant, 'assistant-1', 'tag', ['vip'], 'Hi', 'session-1', 'node-1')],
            'staff notification'   => [SendStaffNotificationJob::class, static fn (): object => new SendStaffNotificationJob($tenant, 'session-1', 'node-1', [], ['email'], 'Hi')],
            'contact message'      => [BroadcastSendJob::class, static fn (): object => new BroadcastSendJob(new OutboundMessage(
                idempotencyKey: 'idem-1',
                tenantId: $tenant,
                channelId: 'channel-1',
                channelType: 'telegram',
                transportToken: 'bot-token',
                chatId: 'chat-1',
                payload: new MessagePayload(type: 'text', text: 'Hello'),
            ))],
        ];
    }

    /**
     * @param  class-string  $class
     * @param  callable(): object  $make
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('droppedJobs')]
    public function test_flow_triggered_work_is_dropped_with_a_log_line(string $class, callable $make): void
    {
        Log::spy();
        Bus::fake([StartFlowFromEventJob::class, BroadcastSendJob::class]);

        $job = $make();
        $this->assertFalse($this->passThrough($job));


        Log::shouldHaveReceived('info')->with('tenant.access_mode.job_dropped', ['job' => $class, 'tenant_id' => self::TENANT_ID])->once();
        Bus::assertNothingDispatched();
    }

    /**
     * @return array<string, array{0: callable(): object}>
     */
    public static function postponedJobs(): array
    {
        $tenant = self::TENANT_ID;

        return [
            'delayed wake-up'      => [static fn (): object => new ResumeDelayedFlowSessionJob($tenant, 'session-1', 'node-1', '2026-10-08T10:00:00+00:00')],
            'send_message timeout' => [static fn (): object => new ResumeTimedOutSendMessageNodeJob($tenant, 'session-1', 'node-1', 'telegram')],
            'broadcast run'        => [static fn (): object => new RunBroadcastJob($tenant, 'broadcast-1')],
        ];
    }

    /**
     * @param  callable(): object  $make
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('postponedJobs')]
    public function test_scheduled_work_waits_for_an_hour_and_runs_once_the_tenant_is_active(callable $make): void
    {
        config(['queue.default' => 'redis']);
        Bus::fake();

        $job = $make();
        $this->assertFalse($this->passThrough($job));

        Bus::assertDispatchedTimes($job::class, 1);
        $copy = Bus::dispatched($job::class)->first();
        $this->assertNotSame($job, $copy);
        $this->assertSame(3600, $copy->delay);
        $this->assertSame($job->queue, $copy->queue);

        $this->mode->resume();

        $this->assertTrue($this->passThrough($copy));
        Bus::assertDispatchedTimes($job::class, 1);
    }

    public function test_a_stopped_broadcast_leaves_recipients_pending_with_one_delayed_rerun_and_sends_each_once_after_resume(): void
    {
        config(['queue.default' => 'redis']);
        Bus::fake();
        $this->mode->resume();

        $channel = $this->channel();
        $first   = $this->contact($channel);
        $second  = $this->contact($channel);
        $bc      = Broadcast::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => (string) $channel->assistant_id,
            'name'         => 'Promo',
            'message'      => 'Hello!',
            'target_type'  => 'all',
            'status'       => BroadcastStatus::Running->value,
        ]);

        // Active: the run materializes the recipients and fans them out.
        $this->app->call([new RunBroadcastJob(self::TENANT_ID, (string) $bc->getKey()), 'handle']);
        $recipientJobs = Bus::dispatched(SendBroadcastRecipientJob::class);
        $this->assertCount(2, $recipientJobs);

        // Stopped: every recipient job is dropped, and the broadcast is queued for one re-run.
        $this->mode->state = FakeTenantAccessMode::stopped()->state;
        foreach ($recipientJobs as $job) {
            $this->assertFalse($this->passThrough($job));
        }

        $this->assertSame(2, DB::table('broadcast_recipients')->where('status', 'pending')->count());
        Bus::assertDispatchedTimes(RunBroadcastJob::class, 1);
        Bus::assertDispatchedTimes(SendBroadcastRecipientJob::class, 2);
        $rerun = Bus::dispatched(RunBroadcastJob::class)->first();
        $this->assertSame(3600, $rerun->delay);

        // Still stopped: the re-run waits another hour, as a single copy.
        $this->assertFalse($this->passThrough($rerun));
        Bus::assertDispatchedTimes(RunBroadcastJob::class, 2);
        $this->assertSame(BroadcastStatus::Running, $bc->refresh()->status);

        // Active again: the re-run fans the Pending recipients out and each is sent once.
        $this->mode->resume();
        $this->assertTrue($this->passThrough(Bus::dispatched(RunBroadcastJob::class)->last(), handle: true));

        $sender = $this->mock(MessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->twice()->andReturn(new DeliveryResult(sent: true, providerMessageId: '1'));
        });
        $this->app->instance(MessageSenderInterface::class, $sender);

        $fanned = Bus::dispatched(SendBroadcastRecipientJob::class)->slice(2);
        $this->assertCount(2, $fanned);
        foreach ($fanned as $job) {
            $this->assertTrue($this->passThrough($job, handle: true));
        }

        $this->assertSame(2, DB::table('broadcast_recipients')->where('status', 'sent')->count());
        $this->assertSame(BroadcastStatus::Completed, $bc->refresh()->status);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
    }

    public function test_an_operator_that_throws_counts_as_active_on_the_job_path(): void
    {
        $this->app->instance(TenantAccessModeInterface::class, new class implements TenantAccessModeInterface {
            public function stateFor(string $tenantId): TenantAccessState
            {
                throw new RuntimeException('operator is down');
            }
        });
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        });

        $this->assertTrue($this->passThrough(new RunBroadcastJob(self::TENANT_ID, 'broadcast-1')));
        $this->assertSame(['operator is down'], $reported);
    }

    public function test_housekeeping_jobs_never_consult_the_access_mode(): void
    {
        CleanupSoftDeletedMediaJob::dispatchSync();

        $this->assertSame([], $this->mode->asked);
    }

    public function test_the_subflow_sweep_skips_a_stopped_tenant_and_still_sees_it_once_active(): void
    {
        $this->assertCount(1, $this->app->make(TenantRepositoryInterface::class)->findAllActive());

        $this->assertSame(0, $this->sweepQueriesOnSessions());

        $this->mode->resume();

        $this->assertGreaterThan(0, $this->sweepQueriesOnSessions());
    }

    private function channel(): Channel
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID, 'default_language' => 'en']);

        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));
    }

    private function contact(Channel $channel): Contact
    {
        $contact = Contact::factory()->forTenant(self::TENANT_ID)->create();
        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);

        return $contact;
    }

    private function sweepQueriesOnSessions(): int
    {
        $count = 0;
        DB::listen(static function ($query) use (&$count): void {
            if (str_contains($query->sql, 'flow_sessions')) {
                $count++;
            }
        });

        $this->artisan('flow:sweep-subflow-timeouts')->assertExitCode(0);

        return $count;
    }

    /**
     * Sends the job through its own middleware, as a worker would. With `$handle` the real
     * handler is the last step. Returns whether the job got as far as running.
     */
    private function passThrough(object $job, bool $handle = false): bool
    {
        $ran = false;

        new Pipeline($this->app)
            ->send($job)
            ->through($job->middleware())
            ->then(function () use (&$ran, $job, $handle): void {
                $ran = true;

                if ($handle) {
                    $this->app->call([$job, 'handle']);
                }
            });

        return $ran;
    }
}
