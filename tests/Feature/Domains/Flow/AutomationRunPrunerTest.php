<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowQuestion;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use App\Domains\Flow\Retention\AutomationRunPruner;
use App\Domains\Flow\Retention\AutomationRunRetention;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Flow\History\HistoryEventType;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Automation run retention deletes only finished automation runs past the cutoff of their class, as whole subflow
 * chains, with their history and logs — and never a conversation session, a running one, another tenant's, or a
 * member of a chain that has to stay.
 */
final class AutomationRunPrunerTest extends FeatureTestCase
{
    private const int SUCCEEDED_DAYS = 7;

    private const int FAILED_DAYS = 30;

    private string $tenantId;

    private CarbonImmutable $now;

    private FlowDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-11-18 12:00:00');
        CarbonImmutable::setTestNow($this->now);
        $this->tenantId   = (string) Str::uuid();
        $this->definition = FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Retention',
            'nodes'     => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'     => [],
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_every_status_is_classified_as_terminal_or_live_and_never_both(): void
    {
        foreach (FlowSessionStatus::cases() as $status) {
            $terminal = in_array($status, AutomationRunRetention::TERMINAL, true);
            $live     = in_array($status, AutomationRunRetention::LIVE, true);

            $this->assertTrue($terminal xor $live, "Status {$status->value} must be classified as exactly one of terminal or live for retention.");
        }
    }

    public function test_only_finished_automation_runs_past_the_cutoff_of_their_class_are_deleted(): void
    {
        $endStatuses = [null, EndStatus::Success->value, EndStatus::Failed->value, EndStatus::Cancelled->value];
        $ages        = [1, 10, 40];
        $expected    = [];

        foreach (FlowSessionStatus::cases() as $status) {
            foreach (FlowRunKind::cases() as $kind) {
                foreach ($endStatuses as $endStatus) {
                    foreach ($ages as $age) {
                        $session = $this->flowRun($kind, $status, $this->now->subDays($age), ['end_status' => $endStatus]);

                        $succeeded = FlowSessionStatus::Completed === $status
                            || (FlowSessionStatus::Ended === $status && EndStatus::Success->value === $endStatus);
                        $terminal = in_array($status, [
                            FlowSessionStatus::Completed, FlowSessionStatus::Ended, FlowSessionStatus::Failed,
                            FlowSessionStatus::Cancelled, FlowSessionStatus::Expired, FlowSessionStatus::TerminatedByUser,
                        ], true);

                        $expected[(string) $session->getKey()] = FlowRunKind::Automation === $kind
                            && $terminal
                            && $age > ($succeeded ? self::SUCCEEDED_DAYS : self::FAILED_DAYS);
                    }
                }
            }
        }

        $deleted = $this->pruner()->prune($this->tenantId, ...$this->cutoffs());

        $remaining = FlowSession::query()->pluck('id')->map(static fn ($id): string => (string) $id)->flip();
        $wrong     = [];

        foreach ($expected as $id => $shouldGo) {
            if ($shouldGo === $remaining->has($id)) {
                $session = $shouldGo ? '(deleted expected, kept)' : '(kept expected, deleted)';
                $wrong[] = $id . ' ' . $session;
            }
        }

        $this->assertSame([], $wrong);
        $this->assertSame(count(array_filter($expected)), $deleted);
        $this->assertGreaterThan(0, $deleted);
    }

    public function test_a_run_exactly_at_the_cutoff_is_kept(): void
    {
        $atCutoff = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(self::SUCCEEDED_DAYS));
        $past     = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(self::SUCCEEDED_DAYS)->subSecond());

        $this->assertSame(1, $this->pruner()->prune($this->tenantId, ...$this->cutoffs()));

        $this->assertTrue($this->exists($atCutoff));
        $this->assertFalse($this->exists($past));
    }

    public function test_another_tenants_runs_in_the_same_schema_are_never_deleted(): void
    {
        $foreign = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Failed, $this->now->subDays(90), ['tenant_id' => (string) Str::uuid()]);

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, ...$this->cutoffs()));

        $this->assertTrue($this->exists($foreign));
    }

    public function test_another_tenants_child_keeps_the_whole_chain(): void
    {
        $parent = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(90));
        $child  = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(90), [
            'tenant_id'             => (string) Str::uuid(),
            'parent_session_id'     => $parent->getKey(),
            'parent_resume_node_id' => 'sub',
        ]);

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, ...$this->cutoffs()));

        $this->assertTrue($this->exists($parent));
        $this->assertTrue($this->exists($child));
    }

    public function test_a_null_cutoff_keeps_its_class_only(): void
    {
        $succeeded = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(90));
        $failed    = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Failed, $this->now->subDays(90));

        $this->assertSame(1, $this->pruner()->prune($this->tenantId, null, $this->now->subDays(self::FAILED_DAYS)));
        $this->assertTrue($this->exists($succeeded));
        $this->assertFalse($this->exists($failed));

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, null, null));
        $this->assertTrue($this->exists($succeeded));
    }

    public function test_a_live_parent_keeps_its_old_finished_child(): void
    {
        $parent = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::WaitingInput, $this->now->subDays(90));
        $child  = $this->child($parent, FlowRunKind::Automation, FlowSessionStatus::Ended, $this->now->subDays(90), EndStatus::Success->value);

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, ...$this->cutoffs()));

        $this->assertTrue($this->exists($parent));
        $this->assertTrue($this->exists($child));
    }

    public function test_a_live_child_keeps_its_old_finished_parent(): void
    {
        $parent = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Failed, $this->now->subDays(90));
        $child  = $this->child($parent, FlowRunKind::Automation, FlowSessionStatus::Active, $this->now->subDays(90));

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, ...$this->cutoffs()));

        $this->assertTrue($this->exists($parent));
        $this->assertTrue($this->exists($child));
    }

    public function test_a_live_grandchild_keeps_the_whole_chain(): void
    {
        $root       = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(90));
        $child      = $this->child($root, FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(90));
        $grandchild = $this->child($child, FlowRunKind::Automation, FlowSessionStatus::PausedSubflow, $this->now->subDays(90));

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, ...$this->cutoffs()));

        $this->assertTrue($this->exists($root));
        $this->assertTrue($this->exists($child));
        $this->assertTrue($this->exists($grandchild));
    }

    public function test_a_young_member_keeps_the_whole_chain(): void
    {
        $parent = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(10));
        $child  = $this->child($parent, FlowRunKind::Automation, FlowSessionStatus::Ended, $this->now->subDays(10), EndStatus::Failed->value);

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, ...$this->cutoffs()));

        $this->assertTrue($this->exists($parent));
        $this->assertTrue($this->exists($child));
    }

    public function test_a_conversation_parent_keeps_its_automation_child(): void
    {
        $parent = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $this->now->subDays(90));
        $child  = $this->child($parent, FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(90));

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, ...$this->cutoffs()));

        $this->assertTrue($this->exists($parent));
        $this->assertTrue($this->exists($child));
    }

    public function test_an_automation_parent_keeps_its_conversation_child_and_itself(): void
    {
        $parent = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(90));
        $child  = $this->child($parent, FlowRunKind::Conversation, FlowSessionStatus::Completed, $this->now->subDays(90));

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, ...$this->cutoffs()));

        $this->assertTrue($this->exists($parent));
        $this->assertTrue($this->exists($child));
    }

    public function test_a_chain_whose_every_member_may_go_is_deleted_whole(): void
    {
        $root       = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Ended, $this->now->subDays(40), ['end_status' => EndStatus::Failed->value]);
        $child      = $this->child($root, FlowRunKind::Automation, FlowSessionStatus::Ended, $this->now->subDays(40), EndStatus::Success->value);
        $grandchild = $this->child($child, FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(40));

        $this->assertSame(3, $this->pruner()->prune($this->tenantId, ...$this->cutoffs()));

        $this->assertFalse($this->exists($root));
        $this->assertFalse($this->exists($child));
        $this->assertFalse($this->exists($grandchild));
    }

    public function test_a_chain_deeper_than_the_limit_is_kept(): void
    {
        $sessions = [$this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(90))];

        for ($level = 1; $level <= AutomationRunPruner::MAX_CHAIN_DEPTH + 1; ++$level) {
            $sessions[] = $this->child($sessions[$level - 1], FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(90));
        }

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, ...$this->cutoffs()));

        foreach ($sessions as $session) {
            $this->assertTrue($this->exists($session));
        }
    }

    public function test_a_child_is_never_picked_on_its_own(): void
    {
        $parent = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Failed, $this->now->subDays(10));
        $child  = $this->child($parent, FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(90));

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, ...$this->cutoffs()));

        $this->assertTrue($this->exists($child));
    }

    public function test_history_and_logs_go_with_the_run_and_stay_with_the_kept_one(): void
    {
        $gone = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Failed, $this->now->subDays(40));
        $kept = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Failed, $this->now->subDays(40));

        foreach ([$gone, $kept] as $session) {
            $this->history($session);
            $this->log($session);
        }

        $this->assertSame(1, $this->pruner()->prune($this->tenantId, ...$this->cutoffs()));

        $this->assertSame(0, FlowSessionHistoryEntry::query()->where('session_id', $gone->getKey())->count());
        $this->assertSame(0, FlowLog::query()->where('session_id', $gone->getKey())->count());
        $this->assertSame(1, FlowSessionHistoryEntry::query()->where('session_id', $kept->getKey())->count());
        $this->assertSame(1, FlowLog::query()->where('session_id', $kept->getKey())->count());
    }

    public function test_the_node_trace_of_a_pruned_run_goes_with_it_and_a_kept_runs_stays(): void
    {
        $gone = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Failed, $this->now->subDays(40));
        $kept = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Failed, $this->now->subDays(10));

        foreach ([$gone, $kept] as $session) {
            foreach ([HistoryEventType::NodeEntered, HistoryEventType::NodeCompleted, HistoryEventType::NodeFailed] as $event) {
                FlowSessionHistoryEntry::query()->create([
                    'tenant_id'  => $session->tenant_id,
                    'session_id' => $session->getKey(),
                    'node_id'    => 'end',
                    'event_type' => $event,
                    'metadata'   => ['type' => 'end', 'input' => ['a' => 1]],
                    'created_at' => $this->now,
                ]);
            }
        }

        $this->assertSame(1, $this->pruner()->prune($this->tenantId, ...$this->cutoffs()));

        $this->assertSame(0, FlowSessionHistoryEntry::query()->where('session_id', $gone->getKey())->count());
        $this->assertSame(3, FlowSessionHistoryEntry::query()->where('session_id', $kept->getKey())->count());
    }

    public function test_an_ask_person_question_goes_with_its_pruned_run_and_stays_with_the_kept_one(): void
    {
        $gone = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Failed, $this->now->subDays(40));
        $kept = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Failed, $this->now->subDays(10));

        foreach ([$gone, $kept] as $session) {
            $this->question($session);
        }

        $this->assertSame(1, $this->pruner()->prune($this->tenantId, ...$this->cutoffs()));

        $this->assertSame(0, FlowQuestion::query()->where('session_id', $gone->getKey())->count());
        $this->assertSame(1, FlowQuestion::query()->where('session_id', $kept->getKey())->count());
    }

    public function test_a_dry_run_counts_exactly_what_the_run_then_deletes(): void
    {
        $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(10));
        $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Failed, $this->now->subDays(10));
        $parent = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Expired, $this->now->subDays(40));
        $this->child($parent, FlowRunKind::Automation, FlowSessionStatus::Ended, $this->now->subDays(40), EndStatus::Failed->value);
        $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $this->now->subDays(40));
        $before = FlowSession::query()->count();

        $wouldDelete = $this->pruner()->prune($this->tenantId, ...[...$this->cutoffs(), true]);

        $this->assertSame(3, $wouldDelete);
        $this->assertSame($before, FlowSession::query()->count());

        $this->assertSame($wouldDelete, $this->pruner()->prune($this->tenantId, ...$this->cutoffs()));
        $this->assertSame($before - $wouldDelete, FlowSession::query()->count());
    }

    public function test_a_backlog_larger_than_a_batch_is_drained_and_capped_per_call(): void
    {
        for ($i = 0; $i < 7; ++$i) {
            $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(40));
        }

        $this->assertSame(4, (new AutomationRunPruner(batchSize: 2, maxBatches: 2))->prune($this->tenantId, ...$this->cutoffs()));
        $this->assertSame(3, (new AutomationRunPruner(batchSize: 2, maxBatches: 10))->prune($this->tenantId, ...$this->cutoffs()));
        $this->assertSame(0, FlowSession::query()->count());
    }

    public function test_a_kept_root_does_not_stall_the_batches_behind_it(): void
    {
        $kept = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(90));
        $this->child($kept, FlowRunKind::Automation, FlowSessionStatus::Active, $this->now->subDays(90));
        $gone = $this->flowRun(FlowRunKind::Automation, FlowSessionStatus::Completed, $this->now->subDays(80));

        $this->assertSame(1, (new AutomationRunPruner(batchSize: 1, maxBatches: 5))->prune($this->tenantId, ...$this->cutoffs()));

        $this->assertTrue($this->exists($kept));
        $this->assertFalse($this->exists($gone));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function cutoffs(): array
    {
        return [$this->now->subDays(self::SUCCEEDED_DAYS), $this->now->subDays(self::FAILED_DAYS)];
    }

    private function pruner(): AutomationRunPruner
    {
        return $this->app->make(AutomationRunPruner::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function flowRun(FlowRunKind $kind, FlowSessionStatus $status, CarbonImmutable $finishedAt, array $attributes = []): FlowSession
    {
        $tenantId = $attributes['tenant_id'] ?? $this->tenantId;
        $contact  = FlowRunKind::Conversation === $kind ? Contact::factory()->forTenant($tenantId)->create() : null;

        $session = FlowSession::query()->create(array_merge([
            'tenant_id'          => $tenantId,
            'contact_id'         => $contact?->getKey(),
            'flow_definition_id' => $this->definition->getKey(),
            'flow_version'       => 1,
            'status'             => $status,
            'run_kind'           => $kind,
            'state'              => [],
        ], $attributes));

        FlowSession::query()->whereKey($session->getKey())->toBase()->update([
            'created_at' => $finishedAt->subHour(),
            'updated_at' => $finishedAt,
        ]);

        return $session;
    }

    private function child(FlowSession $parent, FlowRunKind $kind, FlowSessionStatus $status, CarbonImmutable $finishedAt, ?string $endStatus = null): FlowSession
    {
        return $this->flowRun($kind, $status, $finishedAt, [
            'parent_session_id'     => $parent->getKey(),
            'parent_resume_node_id' => 'sub',
            'end_status'            => $endStatus,
        ]);
    }

    private function exists(FlowSession $session): bool
    {
        return FlowSession::query()->whereKey($session->getKey())->exists();
    }

    private function history(FlowSession $session): void
    {
        FlowSessionHistoryEntry::query()->create([
            'tenant_id'  => $session->tenant_id,
            'session_id' => $session->getKey(),
            'node_id'    => 'end',
            'event_type' => 'state_change',
            'path'       => 'flow.x',
            'created_at' => $this->now,
        ]);
    }

    private function question(FlowSession $session): void
    {
        FlowQuestion::query()->create([
            'tenant_id'     => $session->tenant_id,
            'token'         => Str::random(32),
            'session_id'    => $session->getKey(),
            'node_id'       => 'ask',
            'execution_key' => 'ask:1',
            'contact_id'    => Contact::factory()->forTenant($this->tenantId)->create()->getKey(),
            'buttons'       => [['id' => (string) Str::uuid(), 'value' => 'yes']],
            'status'        => 'expired',
            'expires_at'    => $this->now->subDays(40),
        ]);
    }

    private function log(FlowSession $session): void
    {
        FlowLog::query()->create([
            'session_id'   => $session->getKey(),
            'node_id'      => 'end',
            'node_type'    => 'end',
            'node_version' => 1,
            'status'       => 'terminal',
            'created_at'   => $this->now,
        ]);
    }
}
