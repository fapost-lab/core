<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Admission;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Admission\RefusalReason;
use App\Domains\Flow\Admission\TriggerPauseService;
use App\Domains\Flow\Admission\TriggerRateBreaker;
use App\Domains\Flow\Admission\TriggerRefusals;
use App\Domains\Flow\Admission\TriggerRunAdmission;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowTriggerStateRepositoryInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Models\FlowTriggerState;
use App\Domains\Flow\Orchestration\AutomationRunner;
use App\Domains\Flow\Orchestration\AutomationStartResult;
use App\Domains\Flow\Routing\MessageRouter;
use App\Domains\Flow\Schedule\ScheduleTriggerFirer;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Enums\RefusedWork;
use App\Domains\Tenancy\Events\LimitReached;
use App\Jobs\Flow\NotifyTriggerPausedJob;
use App\Jobs\Flow\StartFlowFromEventJob;
use Carbon\CarbonImmutable;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\Contracts\UsageMeterInterface;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeUsageMeter;

/**
 * Run admission: a trigger's new run passes a pause, the run rate breaker and the plan's run limit, once per fire.
 */
final class TriggerRunAdmissionTest extends FeatureTestCase
{
    private string $tenantId;

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Carbon::setTestNow('2026-11-20 08:30:00');

        $tenant         = $this->app->make(TenantRepositoryInterface::class)->findAllActive()[0];
        $this->tenantId = $tenant->getId();
        $this->app->make(TenantContextInterface::class)->set($tenant);

        $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId, 'default_language' => 'en']);
        $this->app->make(CurrentAssistantInterface::class)->set($this->assistant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_retried_start_of_a_fire_that_has_a_run_is_not_asked_again(): void
    {
        $this->setLimit(2);
        $meter               = $this->meter(FakeUsageMeter::allowing());
        [$draft, $triggerId] = $this->liveSchedule();

        $first  = $this->start($draft, $triggerId, 'fire-1');
        $second = $this->start($draft, $triggerId, 'fire-1');

        $this->assertNotNull($first->session);
        $this->assertFalse($first->continued);
        $this->assertTrue($second->continued);
        $this->assertCount(1, $meter->units, 'The retry spends no second unit of the plan.');
        $this->assertSame(TriggerRunAdmission::LIMIT_KEY, $meter->units[0]->key);
        $this->assertSame(1, FlowSession::query()->count());
        $this->assertStringNotContainsString('fire-1', $meter->units[0]->unitKey, 'The fire key never reaches the meter as it is.');
        $this->assertFalse($this->start($draft, $triggerId, 'fire-2')->isRefused(), 'The retry was not counted in the breaker\'s minute.');
    }

    public function test_a_run_over_the_plan_limit_does_not_start_and_is_counted_on_the_trigger(): void
    {
        $this->meter(FakeUsageMeter::denying(5, 5));
        Event::fake([LimitReached::class]);
        [$draft, $triggerId] = $this->liveSchedule();

        $result = $this->start($draft, $triggerId, 'fire-1');

        $this->assertSame(RefusalReason::PlanLimit, $result->refusal);
        $this->assertSame(0, FlowSession::query()->count());

        $state = $this->state($triggerId);
        $this->assertTrue(Str::isUuid($state->id), 'The row key is a ULID in RFC 4122 form, as the uuid column takes it.');
        $this->assertSame(1, $state->refused_count);
        $this->assertSame(RefusalReason::PlanLimit, $state->last_refusal_reason);
        $this->assertNotNull($state->last_refused_at);
        $this->assertFalse($state->isPaused(), 'The plan limit refuses runs; it never pauses the trigger.');
        Event::assertDispatched(LimitReached::class, static fn (LimitReached $event): bool => RefusedWork::FlowRun === $event->refused
            && TriggerRunAdmission::LIMIT_KEY === $event->key);
    }

    public function test_the_breaker_pauses_the_trigger_once_and_a_paused_trigger_refuses_without_spending_the_plan(): void
    {
        $this->setLimit(2);
        $meter               = $this->meter(FakeUsageMeter::allowing());
        [$draft, $triggerId] = $this->liveSchedule();

        $this->assertFalse($this->start($draft, $triggerId, 'fire-1')->isRefused());
        $this->assertFalse($this->start($draft, $triggerId, 'fire-2')->isRefused());
        $this->assertSame(RefusalReason::RateBreaker, $this->start($draft, $triggerId, 'fire-3')->refusal);
        $this->assertSame(RefusalReason::TriggerPaused, $this->start($draft, $triggerId, 'fire-4')->refusal);

        $this->assertSame(2, FlowSession::query()->count());
        $this->assertCount(2, $meter->units, 'A run the breaker or the pause stops spends no unit of the plan.');

        $state = $this->state($triggerId);
        $this->assertTrue($state->isPaused());
        $this->assertSame(RefusalReason::RateBreaker, $state->paused_reason);
        $this->assertTrue(FlowTrigger::query()->findOrFail($triggerId)->is_active, 'The breaker never touches the live trigger row.');

        Queue::assertPushed(NotifyTriggerPausedJob::class, 1);
        Queue::assertPushed(NotifyTriggerPausedJob::class, fn (NotifyTriggerPausedJob $job): bool => $triggerId === $job->triggerId
            && $draft->flow_id === $job->flowId
            && 'messaging.system' === $job->queue);
    }

    public function test_one_triggers_breaker_does_not_touch_another_trigger_or_another_tenant(): void
    {
        $this->setLimit(1);
        $this->meter(FakeUsageMeter::allowing());
        [$draft, $triggerId]    = $this->liveSchedule();
        [$other, $otherTrigger] = $this->liveSchedule();
        $breaker                = $this->app->make(TriggerRateBreaker::class);

        $this->start($draft, $triggerId, 'fire-1');
        $this->assertSame(RefusalReason::RateBreaker, $this->start($draft, $triggerId, 'fire-2')->refusal);

        $this->assertFalse($this->start($other, $otherTrigger, 'fire-1')->isRefused());
        $this->assertFalse($breaker->hit((string) Str::uuid(), $triggerId), 'The same trigger id in another tenant has its own window.');
        $this->assertNull(FlowTriggerState::query()->where('trigger_id', $otherTrigger)->first()?->paused_at);
    }

    public function test_a_publish_keeps_the_pause_and_a_removed_trigger_takes_its_state_along(): void
    {
        [$draft, $triggerId] = $this->liveSchedule();
        $states              = $this->app->make(FlowTriggerStateRepositoryInterface::class);
        $this->assertTrue($states->pause($triggerId, RefusalReason::RateBreaker, CarbonImmutable::now()));
        $this->assertFalse($states->pause($triggerId, RefusalReason::RateBreaker, CarbonImmutable::now()), 'Only one call pauses it.');

        $this->app->make(PublishFlowService::class)->execute($draft->flow_id);
        $this->assertTrue($states->isPaused($triggerId), 'A publish does not lift a pause.');

        $draft->refresh()->forceFill(['triggers' => []])->save();
        $this->app->make(PublishFlowService::class)->execute($draft->flow_id);

        $this->assertSame(0, FlowTrigger::query()->count());
        $this->assertSame(0, FlowTriggerState::query()->count());
    }

    public function test_a_resume_lifts_the_pause_and_starts_the_window_over(): void
    {
        $this->setLimit(1);
        $this->meter(FakeUsageMeter::allowing());
        [$draft, $triggerId] = $this->liveSchedule();

        $this->start($draft, $triggerId, 'fire-1');
        $this->assertSame(RefusalReason::RateBreaker, $this->start($draft, $triggerId, 'fire-2')->refusal);

        $pauses = $this->app->make(TriggerPauseService::class);
        $this->assertFalse($pauses->resume($this->liveSchedule()[0], $triggerId), 'A trigger of another flow is not resumed.');
        $this->assertTrue($pauses->resume($draft, $triggerId));

        $this->assertFalse($this->start($draft, $triggerId, 'fire-3')->isRefused());

        $state = $this->state($triggerId);
        $this->assertFalse($state->isPaused());
        $this->assertNotNull($state->resumed_at);
        $this->assertSame(1, $state->refused_count, 'The refusals stay counted after a resume.');
    }

    public function test_a_flow_events_start_passes_admission_once_per_trigger_and_event(): void
    {
        $meter               = $this->meter(FakeUsageMeter::allowing());
        [$draft, $triggerId] = $this->liveSchedule();
        $contact             = Contact::factory()->forTenant($this->tenantId)->create();

        $this->reachable($contact);

        $this->runEventJob($draft, $triggerId, $contact, 'event-1');
        $this->runEventJob($draft, $triggerId, $contact, 'event-1');

        $this->assertCount(1, $meter->units, 'A retry of a started event starts nothing and is not asked again.');
        $this->assertSame(1, FlowSession::query()->where('contact_id', $contact->getKey())->count());

        $this->app->make(FlowTriggerStateRepositoryInterface::class)->pause($triggerId, RefusalReason::RateBreaker, CarbonImmutable::now());
        $before = FlowSession::query()->count();

        $this->runEventJob($draft, $triggerId, $contact, 'event-2');

        $this->assertSame($before, FlowSession::query()->count(), 'A paused trigger starts nothing for a flow event.');
        $this->assertSame(RefusalReason::TriggerPaused, $this->state($triggerId)->last_refusal_reason);
    }

    public function test_a_flow_events_start_retried_after_a_failure_that_followed_its_admission_is_not_counted_again(): void
    {
        $this->setLimit(1);
        $meter               = $this->meter(FakeUsageMeter::allowing());
        [$draft, $triggerId] = $this->liveSchedule();
        $contact             = Contact::factory()->forTenant($this->tenantId)->create();
        $this->reachable($contact);

        $engine  = $this->app->make(FlowEngineInterface::class);
        $failing = Mockery::mock(FlowEngineInterface::class);
        $failing->shouldReceive('start')->once()->andThrow(new RuntimeException('engine down'));
        $this->app->instance(FlowEngineInterface::class, $failing);

        try {
            $this->runEventJob($draft, $triggerId, $contact, 'event-1');
            $this->fail('The first attempt fails after its admission.');
        } catch (RuntimeException $exception) {
            $this->assertSame('engine down', $exception->getMessage());
        }

        $this->app->instance(FlowEngineInterface::class, $engine);
        $this->runEventJob($draft, $triggerId, $contact, 'event-1');

        $this->assertSame(1, FlowSession::query()->where('contact_id', $contact->getKey())->count(), 'The retry starts the flow.');
        $this->assertNull(FlowTriggerState::query()->where('trigger_id', $triggerId)->first(), 'The retry is neither refused nor pauses the trigger.');
        $this->assertCount(1, $meter->units);
        $this->assertSame(RefusalReason::RateBreaker, $this->start($draft, $triggerId, 'fire-2')->refusal, 'The event counted once in the minute.');
    }

    public function test_a_message_trigger_never_asks_admission(): void
    {
        $this->setLimit(1);
        $meter     = $this->meter(FakeUsageMeter::allowing());
        $triggerId = mb_strtolower(Str::ulid()->toRfc4122());
        $draft     = FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'        => [],
            'triggers'     => [[
                'id'        => $triggerId,
                'type'      => 'message',
                'is_active' => true,
                'priority'  => 100,
                'config'    => ['keywords' => ['hello'], 'phrases' => []],
            ]],
        ]);
        $this->app->make(PublishFlowService::class)->execute($draft->flow_id);
        $router = $this->app->make(MessageRouter::class);

        foreach (range(1, 2) as $ignored) {
            $contact = Contact::factory()->forTenant($this->tenantId)->create();
            $router->route(contact: $contact, message: $this->incoming('hello'), assistant: $this->assistant, channel: $this->inMemoryChannel());
        }

        $this->assertSame(2, FlowSession::query()->whereNotNull('contact_id')->count(), 'Both conversations started, over a breaker limit of one.');
        $this->assertSame([], $meter->units, 'A conversation spends no unit of flow_runs.');
        $this->assertSame(0, FlowTriggerState::query()->count(), 'Nothing is refused or counted on the trigger.');
    }

    public function test_a_flow_events_start_skipped_for_a_busy_contact_is_not_metered(): void
    {
        $meter               = $this->meter(FakeUsageMeter::allowing());
        [$draft, $triggerId] = $this->liveSchedule();
        $contact             = Contact::factory()->forTenant($this->tenantId)->create();
        $this->reachable($contact);
        $this->liveConversation($draft, $contact, Carbon::now()->subMinutes(5));

        $this->runEventJob($draft, $triggerId, $contact, 'event-1');

        $this->assertSame([], $meter->units);
        $this->assertSame(1, FlowSession::query()->where('contact_id', $contact->getKey())->count());
    }

    public function test_a_flow_events_start_refused_by_admission_ends_no_conversation(): void
    {
        $this->meter(FakeUsageMeter::denying());
        [$draft, $triggerId] = $this->liveSchedule();
        $contact             = Contact::factory()->forTenant($this->tenantId)->create();
        $this->reachable($contact);
        $stale = $this->liveConversation($draft, $contact, Carbon::now()->subHours(2));

        $this->runEventJob($draft, $triggerId, $contact, 'event-1');

        $this->assertSame(FlowSessionStatus::WaitingInput, $stale->refresh()->status);
        $this->assertSame(1, FlowSession::query()->where('contact_id', $contact->getKey())->count());
        $this->assertSame(RefusalReason::PlanLimit, $this->state($triggerId)->last_refusal_reason);
    }

    public function test_a_schedule_slot_refused_by_admission_is_spent_without_a_run(): void
    {
        [, $triggerId] = $this->liveSchedule('0 9 * * *');
        $this->app->make(FlowTriggerStateRepositoryInterface::class)->pause($triggerId, RefusalReason::RateBreaker, CarbonImmutable::now());

        Carbon::setTestNow('2026-11-20 09:00:00');
        $report = $this->app->make(ScheduleTriggerFirer::class)->fireDue($this->tenantId, CarbonImmutable::now());

        $this->assertSame(['fired' => 0, 'skipped' => 1, 'missed' => 0], $report);
        $this->assertSame(0, FlowSession::query()->count());
        $this->assertSame('2026-11-21 09:00:00', FlowTrigger::query()->sole()->next_run_at?->format('Y-m-d H:i:s'), 'The slot is spent.');
        $this->assertSame(1, $this->state($triggerId)->refused_count);
    }

    public function test_a_breaker_that_cannot_count_lets_the_run_through_and_reports(): void
    {
        $this->meter(FakeUsageMeter::allowing());
        [$draft, $triggerId] = $this->liveSchedule();
        $limiter             = Mockery::mock(RateLimiter::class);
        $limiter->shouldReceive('hit')->andThrow(new RuntimeException('redis is down'));
        $this->app->instance(RateLimiter::class, $limiter);
        $handler = Mockery::mock(ExceptionHandler::class)->shouldIgnoreMissing();
        $handler->shouldReceive('report')->once()->with(Mockery::on(static fn ($e): bool => $e instanceof RuntimeException && 'redis is down' === $e->getMessage()));
        $this->app->instance(ExceptionHandler::class, $handler);

        $this->assertFalse($this->start($draft, $triggerId, 'fire-1')->isRefused());
        $this->assertSame(1, FlowSession::query()->count());
    }

    public function test_refusals_in_a_stream_write_the_row_once_per_window_and_the_screen_sees_them_all(): void
    {
        $this->meter(FakeUsageMeter::denying());
        Event::fake([LimitReached::class]);
        [$draft, $triggerId] = $this->liveSchedule();

        $this->start($draft, $triggerId, 'fire-1');
        $this->start($draft, $triggerId, 'fire-2');
        $this->start($draft, $triggerId, 'fire-3');

        $this->assertSame(1, $this->state($triggerId)->refused_count, 'One write in the window.');

        $described = $this->app->make(TriggerPauseService::class)->describe($draft->flow_id);
        $this->assertSame(3, $described[$triggerId]['refused_count']);
        $this->assertSame('plan_limit', $described[$triggerId]['last_refusal_reason']);

        Carbon::setTestNow(Carbon::now()->addSeconds(11));
        $this->start($draft, $triggerId, 'fire-4');

        $this->assertSame(4, $this->state($triggerId)->refused_count, 'The next window writes what the last one counted.');
    }

    public function test_a_refusal_the_cache_counted_is_not_written_directly_when_the_cache_fails_after_it(): void
    {
        [, $triggerId] = $this->liveSchedule();
        $cache         = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('add')->once()->andReturn(true);
        $cache->shouldReceive('increment')->once()->andReturn(1);
        $cache->shouldReceive('put')->andThrow(new RuntimeException('cache lost'));
        $this->app->instance(ExceptionHandler::class, Mockery::mock(ExceptionHandler::class)->shouldIgnoreMissing());

        $this->refusals($cache)->record($this->tenantId, $triggerId, RefusalReason::PlanLimit, CarbonImmutable::now());

        $this->assertNull(FlowTriggerState::query()->where('trigger_id', $triggerId)->first(), 'The counted refusal waits in the cache for the next write.');
    }

    public function test_a_refusal_the_cache_could_not_count_is_written_directly(): void
    {
        [, $triggerId] = $this->liveSchedule();
        $cache         = Mockery::mock(CacheRepository::class);
        $cache->shouldReceive('add')->once()->andReturn(true);
        $cache->shouldReceive('increment')->once()->andReturn(false);
        $this->app->instance(ExceptionHandler::class, Mockery::mock(ExceptionHandler::class)->shouldIgnoreMissing());

        $this->refusals($cache)->record($this->tenantId, $triggerId, RefusalReason::PlanLimit, CarbonImmutable::now());

        $this->assertSame(1, $this->state($triggerId)->refused_count);
    }

    public function test_the_flow_runs_limit_is_registered_per_period(): void
    {
        $definition = $this->app->make(LimitRegistryInterface::class)->find(TriggerRunAdmission::LIMIT_KEY);

        $this->assertNotNull($definition);
        $this->assertSame(LimitKind::PerPeriod, $definition->kind);
    }

    public function test_the_pause_notice_reaches_whoever_can_resume_the_trigger_in_app(): void
    {
        $this->seed(TenantAclSeeder::class);
        [$draft, $triggerId] = $this->liveSchedule();
        $manager             = User::factory()->create();
        $manager->givePermissionTo([Permission::ManageAutomations->value, Permission::ManageFlowDefinitions->value]);
        $manager->assistants()->attach($this->assistant->getKey());
        $elsewhere = User::factory()->create();
        $elsewhere->givePermissionTo([Permission::ManageAutomations->value, Permission::ManageFlowDefinitions->value]);
        $bystander = User::factory()->create();

        $job = new NotifyTriggerPausedJob($this->tenantId, $draft->flow_id, $triggerId, 'schedule', CarbonImmutable::now()->format(DATE_ATOM));
        $this->app->call([$job, 'handle']);
        $this->app->call([$job, 'handle']);

        $this->assertSame(1, $manager->notifications()->count(), 'A retried notice is delivered once.');
        $this->assertSame(0, $elsewhere->notifications()->count(), 'Managing automations without access to the flow\'s assistant is not enough.');
        $this->assertSame(0, $bystander->notifications()->count());
        $data = $manager->notifications()->sole()->data;
        $this->assertStringContainsString($draft->name, $data['title']);
        $this->assertSame(route('builder.flows.show', ['flow' => $draft->flow_id], false), $data['actions'][0]['url']);
    }

    public function test_a_pause_notice_that_failed_before_delivery_is_delivered_on_its_retry(): void
    {
        $this->seed(TenantAclSeeder::class);
        [$draft, $triggerId] = $this->liveSchedule();
        $manager             = User::factory()->create();
        $manager->givePermissionTo([Permission::ManageAutomations->value, Permission::ManageFlowDefinitions->value]);
        $manager->assistants()->attach($this->assistant->getKey());

        $settings = $this->app->make(PlatformSettingsReaderInterface::class);
        $failing  = Mockery::mock(PlatformSettingsReaderInterface::class);
        $failing->shouldReceive('integer')->once()->andThrow(new RuntimeException('settings down'));
        $this->app->instance(PlatformSettingsReaderInterface::class, $failing);

        $job = new NotifyTriggerPausedJob($this->tenantId, $draft->flow_id, $triggerId, 'schedule', CarbonImmutable::now()->format(DATE_ATOM));

        try {
            $this->app->call([$job, 'handle']);
            $this->fail('The first attempt fails before delivery.');
        } catch (RuntimeException) {
            $this->assertSame(0, $manager->notifications()->count());
        }

        $this->app->instance(PlatformSettingsReaderInterface::class, $settings);
        $this->app->call([$job, 'handle']);

        $this->assertSame(1, $manager->notifications()->count(), 'The retry is not a no-op.');
    }

    private function inMemoryChannel(): Channel
    {
        // The router only reads the channel's type and token; a saved channel would call Telegram from its observer.
        $channel = Channel::factory()->make([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'noop-token',
        ]);
        $channel->id     = (string) Str::uuid();
        $channel->exists = true;

        return $channel;
    }

    private function incoming(string $text): IncomingMessage
    {
        return new IncomingMessage(
            updateId: 'upd-' . Str::uuid()->toString(),
            externalUserId: 'ext-user-' . Str::random(6),
            externalChatId: 'ext-chat-' . Str::random(6),
            text: $text,
            type: IncomingMessageType::Text,
            platform: 'telegram',
        );
    }

    private function refusals(CacheRepository $cache): TriggerRefusals
    {
        return new TriggerRefusals($this->app->make(FlowTriggerStateRepositoryInterface::class), $cache);
    }

    private function setLimit(int $runs): void
    {
        config(['flow.automation.runs_per_trigger_per_minute' => $runs]);
    }

    private function meter(FakeUsageMeter $meter): FakeUsageMeter
    {
        $this->app->instance(UsageMeterInterface::class, $meter);

        return $meter;
    }

    private function start(FlowDraft $draft, string $triggerId, string $fireKey): AutomationStartResult
    {
        return $this->app->make(AutomationRunner::class)->start($this->tenantId, $draft->flow_id, $triggerId, $fireKey);
    }

    private function state(string $triggerId): FlowTriggerState
    {
        return FlowTriggerState::query()->where('trigger_id', $triggerId)->sole();
    }

    private function runEventJob(FlowDraft $draft, string $triggerId, Contact $contact, string $eventId): void
    {
        $job = new StartFlowFromEventJob(
            tenantId: $this->tenantId,
            flowId: $draft->flow_id,
            assistantId: (string) $this->assistant->getKey(),
            contactId: (string) $contact->getKey(),
            payload: [],
            eventName: 'order.created',
            triggerId: $triggerId,
            eventId: $eventId,
        );

        $this->app->call([$job, 'handle']);
    }

    /**
     * A published flow whose one trigger is a schedule; its runs are started directly through the runner.
     *
     * @return array{FlowDraft, string}
     */
    private function reachable(Contact $contact): void
    {
        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $this->assistant->getKey(),
            'tenant_id'    => $this->tenantId,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));

        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);
    }

    private function liveConversation(FlowDraft $draft, Contact $contact, Carbon $updatedAt): FlowSession
    {
        $definition = FlowDefinition::query()->where('flow_id', $draft->flow_id)->where('is_active', true)->sole();

        $session = FlowSession::query()->create([
            'tenant_id'          => $this->tenantId,
            'assistant_id'       => $this->assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => $definition->version,
            'current_node_id'    => 'end',
            'state'              => [],
            'status'             => FlowSessionStatus::WaitingInput,
            'version'            => 1,
        ]);

        FlowSession::query()->whereKey($session->getKey())->update(['updated_at' => $updatedAt]);

        return $session->refresh();
    }

    private function liveSchedule(string $cron = '0 9 * * *'): array
    {
        $triggerId = mb_strtolower(Str::ulid()->toRfc4122());
        $draft     = FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'        => [],
            'triggers'     => [[
                'id'        => $triggerId,
                'type'      => 'schedule',
                'is_active' => true,
                'priority'  => 100,
                'config'    => ['cron' => $cron, 'timezone' => 'UTC'],
            ]],
        ]);

        $this->app->make(PublishFlowService::class)->execute($draft->flow_id);

        return [$draft, $triggerId];
    }
}
