<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowTriggerState;
use App\Domains\Flow\Orchestration\AutomationRunner;
use App\Domains\Flow\Orchestration\AutomationStartResult;
use App\Domains\Flow\Schedule\ScheduleTriggerFirer;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Jobs\Flow\StartAutomationRunJob;
use App\Jobs\Flow\StartFlowFromEventJob;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Quota\Contracts\UsageMeterInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeUsageMeter;

/**
 * The flow's on/off switch in the flows list (`flow_drafts.is_active`) gates its automatic starts: a switched-off flow
 * starts no run from a trigger's fire, run admission is not asked for it, and turning it back on catches nothing up.
 * A fire that already has a run (a schedule's `pending` run claimed while the flow was on) still goes on.
 */
#[Group('redis')]
final class FlowSwitchGatesAutomaticStartsTest extends FeatureTestCase
{
    private string $tenantId;

    private Assistant $assistant;

    private FakeUsageMeter $meter;

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

        $this->meter = FakeUsageMeter::allowing();
        $this->app->instance(UsageMeterInterface::class, $this->meter);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_switch_reads_off_only_for_a_draft_switched_off(): void
    {
        $drafts = $this->app->make(FlowDraftRepositoryInterface::class);
        $on     = FlowDraft::factory()->create(['tenant_id' => $this->tenantId, 'assistant_id' => $this->assistant->getKey()]);
        $off    = FlowDraft::factory()->create(['tenant_id' => $this->tenantId, 'assistant_id' => $this->assistant->getKey(), 'is_active' => false]);

        $this->assertFalse($drafts->isSwitchedOff($on->flow_id));
        $this->assertTrue($drafts->isSwitchedOff($off->flow_id));
        $this->assertFalse($drafts->isSwitchedOff((string) Str::uuid()), 'A flow without a draft is left to each start path.');
    }

    public function test_a_fire_of_a_switched_off_flow_starts_nothing_and_asks_no_admission(): void
    {
        Log::spy();
        [$draft, $triggerId] = $this->liveSchedule();
        $this->switchOff($draft);

        $result = $this->start($draft, $triggerId, 'fire-1');

        $this->assertTrue($result->isFlowOff());
        $this->assertNull($result->session);
        $this->assertFalse($result->isRefused());
        $this->assertSame(0, FlowSession::query()->count());
        $this->assertSame([], $this->meter->units, 'No unit of the plan is spent.');
        $this->assertSame(0, FlowTriggerState::query()->count(), 'Nothing is counted on the trigger.');
        Log::shouldHaveReceived('info')->with('flow.automation.start_skipped_flow_off', Mockery::on(
            fn (array $context): bool => $triggerId === $context['trigger_id'] && 'fire-1' === $context['fire_key'],
        ))->once();

        // Turned back on: the dropped fire is not caught up, a new one starts.
        $draft->forceFill(['is_active' => true])->save();

        $this->assertNotNull($this->start($draft, $triggerId, 'fire-2')->session);
        $this->assertSame(['fire-2'], FlowSession::query()->pluck('fire_key')->all());
    }

    public function test_a_pending_run_claimed_before_the_switch_off_still_starts(): void
    {
        [$draft] = $this->liveSchedule('0 9 * * *');
        Carbon::setTestNow('2026-11-20 09:00:00');
        $this->app->make(ScheduleTriggerFirer::class)->fireDue($this->tenantId, CarbonImmutable::now());
        $this->assertSame(FlowSessionStatus::Pending, FlowSession::query()->sole()->status);

        $this->switchOff($draft);
        $this->app->call([Queue::pushed(StartAutomationRunJob::class)->sole(), 'handle']);

        $this->assertSame(FlowSessionStatus::Ended, FlowSession::query()->sole()->status);
    }

    public function test_a_flow_event_of_a_switched_off_flow_starts_nothing_and_asks_no_admission(): void
    {
        [$draft, $triggerId] = $this->liveSchedule();
        $contact             = Contact::factory()->forTenant($this->tenantId)->create();
        $this->switchOff($draft);

        $this->runEventJob($draft, $triggerId, $contact, 'event-1');

        $this->assertSame(0, FlowSession::query()->count());
        $this->assertSame([], $this->meter->units);
        $this->assertSame(0, FlowTriggerState::query()->count());

        $draft->forceFill(['is_active' => true])->save();
        $this->runEventJob($draft, $triggerId, $contact, 'event-2');

        $this->assertSame(1, FlowSession::query()->count());
    }

    private function switchOff(FlowDraft $draft): void
    {
        $draft->forceFill(['is_active' => false])->save();
    }

    private function start(FlowDraft $draft, string $triggerId, string $fireKey): AutomationStartResult
    {
        return $this->app->make(AutomationRunner::class)->start($this->tenantId, $draft->flow_id, $triggerId, $fireKey);
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
     * A published flow whose one trigger is a schedule; its runs are started directly through the runner too.
     *
     * @return array{FlowDraft, string}
     */
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
