<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\TriggerTypes;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Admission\RefusalReason;
use App\Domains\Flow\Admission\TriggerRunAdmission;
use App\Domains\Flow\Contracts\FlowTriggerStateRepositoryInterface;
use App\Domains\Flow\Contracts\SolutionAvailabilityInterface;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Models\FlowTriggerState;
use App\Domains\Flow\Registry\TriggerTypeRegistry;
use App\Domains\Flow\Services\EventChainContext;
use App\Jobs\Flow\FireRegisteredTriggerJob;
use App\Jobs\Flow\StartAutomationRunJob;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Flow\Contracts\TriggerFirerInterface;
use Fapost\Foundation\Quota\Contracts\UsageMeterInterface;
use Fapost\Foundation\Quota\DTO\UsageDecision;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;
use Tests\Fixtures\Solution\Triggers\FeedbackSubmittedTrigger;
use Tests\Support\FakeUsageMeter;

/**
 * A trigger type the fixture Solution registers (`feedback.submitted`): a fire starts one contactless run per matching
 * active trigger, once per fire key, only while the Solution is active, and not past the chain depth limit.
 */
#[Group('redis')]
final class RegisteredTriggerTypesTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    /** @var list<string> */
    private array $active = ['feedback'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(TriggerTypeRegistry::class)->register(FeedbackSubmittedTrigger::class, 'feedback');

        $test = $this;
        $this->app->instance(SolutionAvailabilityInterface::class, new class ($test) implements SolutionAvailabilityInterface {
            public function __construct(private readonly RegisteredTriggerTypesTest $test)
            {
            }

            public function isAvailable(?string $solutionId): bool
            {
                return null === $solutionId || in_array($solutionId, $this->test->activeSolutions(), true);
            }

            public function isReady(string $solutionId): bool
            {
                return true;
            }
        });
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function refusedFires(): array
    {
        return [
            'unregistered type' => ['feedback.unknown', 'entry-1', []],
            'empty fire key'    => [FeedbackSubmittedTrigger::TYPE, '', []],
            'too large payload' => [FeedbackSubmittedTrigger::TYPE, 'entry-1', ['comment' => str_repeat('x', TriggerFirerInterface::MAX_PAYLOAD_BYTES)]],
            'not serializable'  => [FeedbackSubmittedTrigger::TYPE, 'entry-1', ['comment' => "\xB1\x31"]],
        ];
    }

    /**
     * @return list<string>
     */
    public function activeSolutions(): array
    {
        return $this->active;
    }

    public function test_a_fire_starts_one_contactless_run_per_matching_trigger_once_per_fire_key(): void
    {
        Queue::fake();

        $matching = $this->trigger(['min_score' => 3]);
        $this->trigger(['min_score' => 5]);
        $this->trigger(['min_score' => 1], active: false);

        $this->firer()->fire(self::TENANT_ID, FeedbackSubmittedTrigger::TYPE, 'entry-1', ['score' => 4, 'comment' => 'Fine']);

        $fire = Queue::pushed(FireRegisteredTriggerJob::class)->sole();
        $this->assertSame(FireRegisteredTriggerJob::QUEUE, $fire->queue);
        $this->assertSame('tt:entry-1', $fire->fireKey);
        $this->assertSame(1, $fire->chainDepth);

        // The fan-out runs twice, as a retry would: the run still starts once.
        $this->app->call([$fire, 'handle']);
        $this->app->call([$fire, 'handle']);

        $starts = Queue::pushed(StartAutomationRunJob::class);
        $this->assertCount(2, $starts);
        $this->assertSame([(string) $matching->getKey()], $starts->pluck('triggerId')->unique()->values()->all());
        $starts->each(fn (StartAutomationRunJob $job) => $this->app->call([$job, 'handle']));

        $session = FlowSession::query()->sole();
        $this->assertNull($session->contact_id);
        $this->assertSame(FlowRunKind::Automation, $session->run_kind);
        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertSame('tt:entry-1', $session->fire_key);
        $this->assertSame(['score' => 4, 'comment' => 'Fine'], $session->state['flow']['trigger']);
        $this->assertSame(FeedbackSubmittedTrigger::TYPE, $session->state['flow']['trigger_type']);
    }

    public function test_a_registered_triggers_run_passes_run_admission(): void
    {
        Queue::fake();
        $meter = new FakeUsageMeter(UsageDecision::allowed());
        $this->app->instance(UsageMeterInterface::class, $meter);
        $trigger = $this->trigger(['min_score' => 1]);

        $this->fireAndStart('entry-1');

        $this->assertSame(1, FlowSession::query()->count());
        $this->assertCount(1, $meter->units, 'The run spends one unit of the plan.');
        $this->assertSame(TriggerRunAdmission::LIMIT_KEY, $meter->units[0]->key);

        $this->app->make(FlowTriggerStateRepositoryInterface::class)->pause($trigger->id, RefusalReason::RateBreaker, CarbonImmutable::now());
        $this->fireAndStart('entry-2');

        $this->assertSame(1, FlowSession::query()->count(), 'A paused registered trigger starts nothing.');
        $state = FlowTriggerState::query()->where('trigger_id', $trigger->id)->sole();
        $this->assertSame(1, $state->refused_count);
        $this->assertSame(RefusalReason::TriggerPaused, $state->last_refusal_reason);
    }

    public function test_a_fire_of_a_flow_switched_off_in_the_flows_list_starts_no_run_and_spends_no_unit(): void
    {
        Queue::fake();
        $meter = new FakeUsageMeter(UsageDecision::allowed());
        $this->app->instance(UsageMeterInterface::class, $meter);
        $trigger = $this->trigger(['min_score' => 1]);
        FlowDraft::factory()->create([
            'tenant_id'    => self::TENANT_ID,
            'flow_id'      => $trigger->flow_id,
            'assistant_id' => Assistant::factory()->create(['tenant_id' => self::TENANT_ID])->getKey(),
            'is_active'    => false,
        ]);

        $this->fireAndStart('entry-1');

        $this->assertSame(0, FlowSession::query()->count());
        $this->assertSame([], $meter->units);
        $this->assertSame(0, FlowTriggerState::query()->count());
    }

    public function test_nothing_starts_while_the_solution_is_not_active_for_the_tenant(): void
    {
        Queue::fake();
        $this->trigger(['min_score' => 1]);
        $this->active = [];

        $this->firer()->fire(self::TENANT_ID, FeedbackSubmittedTrigger::TYPE, 'entry-1', ['score' => 4]);
        $this->app->call([Queue::pushed(FireRegisteredTriggerJob::class)->sole(), 'handle']);

        Queue::assertNotPushed(StartAutomationRunJob::class);
    }

    public function test_a_fire_past_the_chain_depth_limit_queues_nothing(): void
    {
        Queue::fake();
        $this->trigger(['min_score' => 1]);
        $this->app->make(EventChainContext::class)->enter(5);

        $this->firer()->fire(self::TENANT_ID, FeedbackSubmittedTrigger::TYPE, 'entry-1', ['score' => 4]);

        Queue::assertNotPushed(FireRegisteredTriggerJob::class);
    }

    public function test_a_fire_inside_a_run_is_one_level_deeper(): void
    {
        Queue::fake();
        $this->app->make(EventChainContext::class)->enter(2);

        $this->firer()->fire(self::TENANT_ID, FeedbackSubmittedTrigger::TYPE, 'entry-1', ['score' => 4]);

        $this->assertSame(3, Queue::pushed(FireRegisteredTriggerJob::class)->sole()->chainDepth);
    }

    public function test_a_long_or_binary_fire_key_is_hashed_into_the_column(): void
    {
        Queue::fake();

        $this->firer()->fire(self::TENANT_ID, FeedbackSubmittedTrigger::TYPE, str_repeat('k', 300), ['score' => 4]);

        $this->assertSame('tt:sha256:' . hash('sha256', str_repeat('k', 300)), Queue::pushed(FireRegisteredTriggerJob::class)->sole()->fireKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedFires')]
    public function test_a_fire_the_firer_cannot_take_throws_at_the_caller(string $type, string $fireKey, array $payload): void
    {
        Queue::fake();

        try {
            $this->firer()->fire(self::TENANT_ID, $type, $fireKey, $payload);
            $this->fail('The fire was accepted.');
        } catch (InvalidArgumentException) {
            Queue::assertNothingPushed();
        }
    }

    private function fireAndStart(string $fireKey): void
    {
        $this->firer()->fire(self::TENANT_ID, FeedbackSubmittedTrigger::TYPE, $fireKey, ['score' => 4, 'comment' => 'Fine']);

        foreach (Queue::pushed(FireRegisteredTriggerJob::class)->filter(static fn (FireRegisteredTriggerJob $job): bool => 'tt:' . $fireKey === $job->fireKey) as $fire) {
            $this->app->call([$fire, 'handle']);
        }

        foreach (Queue::pushed(StartAutomationRunJob::class)->filter(static fn (StartAutomationRunJob $job): bool => 'tt:' . $fireKey === $job->fireKey) as $start) {
            $this->app->call([$start, 'handle']);
        }
    }

    private function firer(): TriggerFirerInterface
    {
        return $this->app->make(TriggerFirerInterface::class);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function trigger(array $config, bool $active = true): FlowTrigger
    {
        $flowId = (string) Str::uuid();

        FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flowId,
            'version'   => 1,
            'name'      => 'Feedback flow',
            'nodes'     => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'     => [],
            'is_active' => true,
        ]);

        return FlowTrigger::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => null,
            'flow_id'      => $flowId,
            'type'         => FeedbackSubmittedTrigger::TYPE,
            'is_active'    => $active,
            'priority'     => 0,
            'config'       => $config,
        ]);
    }
}
