<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Orchestration\StrandedAutomationRunRecovery;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Jobs\Flow\ContinueAutomationRunJob;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\FeatureTestCase;

/**
 * Which automation runs the recovery pass hands on, and how: only a run `active` (or a parent over a child that is
 * already over) with no movement past the threshold, on time, with a free lock. That a recovered run then runs to its
 * end is proven in `AutomationRunTest`.
 */
final class StrandedAutomationRunRecoveryTest extends FeatureTestCase
{
    private string $tenantId;

    private CarbonImmutable $now;

    private FlowDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->now = CarbonImmutable::parse('2026-11-25 12:00:00');
        $this->travelTo($this->now);
        $this->tenantId = (string) Str::uuid();
        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: $this->tenantId, schemaName: 'main'));
        $this->definition = FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Recovery',
            'nodes'     => [
                ['id' => 'n1', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => (string) Str::uuid(), 'timeout' => 'PT1H']],
                ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
            ],
            'edges'     => [['id' => 'e1', 'from' => 'n1', 'to' => 'end', 'handle' => 'success']],
            'is_active' => true,
        ]);
    }

    public function test_an_idle_run_is_queued_again_with_its_own_segment_and_logged(): void
    {
        Log::spy();
        $segmentId = (string) Str::uuid();
        $run       = $this->automation(FlowSessionStatus::Active, ['segment_id' => $segmentId], idleMinutes: 11);

        $this->assertSame(['recovered_runs' => 1, 'skipped_busy' => 0], $this->recover());

        $job = Queue::pushed(ContinueAutomationRunJob::class)->sole();
        $this->assertSame((string) $run->getKey(), $job->sessionId);
        $this->assertSame($segmentId, $job->segmentId);
        $this->assertSame($segmentId, $run->refresh()->segment_id, 'A continuation still queued stays valid.');
        Log::shouldHaveReceived('info')->with('flow.automation.run_recovered', Mockery::on(
            static fn (array $context): bool => (string) $run->getKey() === $context['root_id']
                && $segmentId === $context['segment_id']
                && 'active' === $context['shape']
                && 660 === $context['idle_seconds'],
        ))->once();
    }

    public function test_a_run_without_a_segment_yet_gets_its_first_one(): void
    {
        $run = $this->automation(FlowSessionStatus::Active, idleMinutes: 11);

        $this->recover();

        $segmentId = $run->refresh()->segment_id;
        $this->assertIsString($segmentId);
        $this->assertSame($segmentId, Queue::pushed(ContinueAutomationRunJob::class)->sole()->segmentId);
    }

    public function test_a_run_that_moved_within_the_threshold_is_left_alone_and_any_session_of_it_counts(): void
    {
        $this->automation(FlowSessionStatus::Active, idleMinutes: 9);
        $parent = $this->automation(FlowSessionStatus::PausedSubflow, ['current_node_id' => 'n1'], idleMinutes: 30);
        $this->automation(FlowSessionStatus::Ended, [
            'parent_session_id'     => $parent->getKey(),
            'parent_resume_node_id' => 'n1',
            'trigger_id'            => $parent->trigger_id,
        ], idleMinutes: 5);

        $this->assertSame(['recovered_runs' => 0, 'skipped_busy' => 0], $this->recover());
        Queue::assertNothingPushed();
    }

    public function test_a_parent_over_a_child_that_is_already_over_is_handed_on(): void
    {
        $parent = $this->automation(FlowSessionStatus::PausedSubflow, ['current_node_id' => 'n1'], idleMinutes: 30);
        $this->automation(FlowSessionStatus::Completed, [
            'parent_session_id'     => $parent->getKey(),
            'parent_resume_node_id' => 'n1',
            'trigger_id'            => $parent->trigger_id,
        ], idleMinutes: 12);

        $this->assertSame(['recovered_runs' => 1, 'skipped_busy' => 0], $this->recover());
        $this->assertSame((string) $parent->getKey(), Queue::pushed(ContinueAutomationRunJob::class)->sole()->sessionId);
        $this->assertSame(FlowSessionStatus::PausedSubflow, $parent->refresh()->status, 'Recovery runs no node.');
    }

    public function test_a_run_whose_child_still_moves_is_not_even_locked(): void
    {
        $parent = $this->automation(FlowSessionStatus::PausedSubflow, ['current_node_id' => 'n1'], idleMinutes: 30);
        $this->automation(FlowSessionStatus::Active, [
            'parent_session_id'     => $parent->getKey(),
            'parent_resume_node_id' => 'n1',
            'trigger_id'            => $parent->trigger_id,
        ], idleMinutes: 1);
        $this->guardBusyFor($parent);

        $this->assertSame(['recovered_runs' => 0, 'skipped_busy' => 0], $this->recover());
        Queue::assertNothingPushed();
    }

    public function test_the_same_segment_is_queued_again_at_most_once_per_threshold(): void
    {
        $this->automation(FlowSessionStatus::Active, ['segment_id' => (string) Str::uuid()], idleMinutes: 11);

        $this->recover();
        $this->travel(9)->minutes();
        $this->assertSame(0, $this->recover()['recovered_runs']);
        $this->travel(2)->minutes();
        $this->assertSame(1, $this->recover()['recovered_runs']);

        Queue::assertPushed(ContinueAutomationRunJob::class, 2);
    }

    public function test_a_run_whose_lock_is_busy_is_skipped_and_the_others_are_still_recovered(): void
    {
        $busy = $this->automation(FlowSessionStatus::Active, ['segment_id' => (string) Str::uuid()], idleMinutes: 11);
        $free = $this->automation(FlowSessionStatus::Active, ['segment_id' => (string) Str::uuid()], idleMinutes: 11);
        $this->guardBusyFor($busy);

        $this->assertSame(['recovered_runs' => 1, 'skipped_busy' => 1], $this->recover());
        $this->assertSame((string) $free->getKey(), Queue::pushed(ContinueAutomationRunJob::class)->sole()->sessionId);
    }

    public function test_overdue_parked_and_conversation_runs_and_other_tenants_are_never_touched(): void
    {
        $this->automation(FlowSessionStatus::Active, ['deadline_at' => $this->now->subMinute()], idleMinutes: 11);
        $this->automation(FlowSessionStatus::Paused, idleMinutes: 60);
        $this->automation(FlowSessionStatus::WaitingInput, idleMinutes: 60);
        $parked = $this->automation(FlowSessionStatus::PausedSubflow, ['current_node_id' => 'n1'], idleMinutes: 60);
        $this->automation(FlowSessionStatus::WaitingInput, [
            'parent_session_id'     => $parked->getKey(),
            'parent_resume_node_id' => 'n1',
            'trigger_id'            => $parked->trigger_id,
        ], idleMinutes: 60);
        $this->automation(FlowSessionStatus::Active, ['tenant_id' => (string) Str::uuid()], idleMinutes: 60);
        $this->conversation(idleMinutes: 60);

        $this->assertSame(['recovered_runs' => 0, 'skipped_busy' => 0], $this->recover());
        Queue::assertNothingPushed();
    }

    public function test_the_threshold_follows_the_setting(): void
    {
        config(['flow.automation.recover_after_minutes' => 30]);
        $this->automation(FlowSessionStatus::Active, idleMinutes: 20);

        $this->assertSame(0, $this->recover()['recovered_runs']);

        $this->travel(11)->minutes();
        $this->assertSame(1, $this->recover()['recovered_runs']);
    }

    /**
     * @return array{recovered_runs: int, skipped_busy: int}
     */
    private function recover(): array
    {
        return $this->app->make(StrandedAutomationRunRecovery::class)->recover($this->tenantId);
    }

    /**
     * Every lock is free but {@code $busy}'s, which another worker holds.
     */
    private function guardBusyFor(FlowSession $busy): void
    {
        $busyKey = LockScope::ofSession($busy)->key();

        $this->app->instance(FlowExecutionGuardInterface::class, new class ($busyKey) implements FlowExecutionGuardInterface {
            public function __construct(private string $busyKey)
            {
            }

            public function run(string $tenantId, string $contactId, string $assistantId, Closure $callback): mixed
            {
                return $callback();
            }

            public function runInScope(LockScope $scope, Closure $callback): mixed
            {
                if ($scope->key() === $this->busyKey) {
                    throw new SessionLockTimeoutException($scope->key());
                }

                return $callback();
            }
        });
    }

    /**
     * A contactless automation session of its own trigger, last written {@code $idleMinutes} ago.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function automation(FlowSessionStatus $status, array $overrides = [], int $idleMinutes = 0): FlowSession
    {
        return $this->flowSession($status, [
            'run_kind'   => FlowRunKind::Automation,
            'trigger_id' => (string) Str::uuid(),
            ...$overrides,
        ], $idleMinutes);
    }

    private function conversation(int $idleMinutes): FlowSession
    {
        return $this->flowSession(FlowSessionStatus::Active, [
            'run_kind'     => FlowRunKind::Conversation,
            'contact_id'   => Contact::factory()->create(['tenant_id' => $this->tenantId])->getKey(),
            'assistant_id' => Assistant::factory()->create(['tenant_id' => $this->tenantId])->getKey(),
        ], $idleMinutes);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function flowSession(FlowSessionStatus $status, array $overrides, int $idleMinutes): FlowSession
    {
        return FlowSession::query()->forceCreate([
            'tenant_id'          => $this->tenantId,
            'assistant_id'       => null,
            'contact_id'         => null,
            'flow_definition_id' => $this->definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'end',
            'state'              => [],
            'status'             => $status,
            'version'            => 1,
            'created_at'         => $this->now->subHour(),
            'updated_at'         => $this->now->subMinutes($idleMinutes),
            ...$overrides,
        ]);
    }
}
