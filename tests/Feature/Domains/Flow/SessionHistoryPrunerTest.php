<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use App\Domains\Flow\Retention\AutomationRunRetention;
use App\Domains\Flow\Retention\SessionHistoryPruner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Session history retention deletes the whole history of a finished conversation session past the cutoff — and never
 * the session itself, a session still going, its subflow child while it runs, an automation run's, or another
 * tenant's.
 */
final class SessionHistoryPrunerTest extends FeatureTestCase
{
    private const int RETENTION_DAYS = 90;

    private string $tenantId;

    private CarbonImmutable $now;

    private FlowDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-11-27 12:00:00');
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

    public function test_only_finished_conversation_sessions_past_the_cutoff_lose_their_history(): void
    {
        $old   = $this->now->subDays(self::RETENTION_DAYS + 1);
        $young = $this->now->subDays(self::RETENTION_DAYS - 1);

        $expected = [];

        foreach (AutomationRunRetention::TERMINAL as $status) {
            $expected[] = [$this->flowRun(FlowRunKind::Conversation, $status, $old), false];
            $expected[] = [$this->flowRun(FlowRunKind::Conversation, $status, $young), true];
            $expected[] = [$this->flowRun(FlowRunKind::Automation, $status, $old), true];
        }

        foreach (AutomationRunRetention::LIVE as $status) {
            $expected[] = [$this->flowRun(FlowRunKind::Conversation, $status, $old), true];
        }

        $pruned = $this->pruner()->prune($this->tenantId, $this->cutoff());

        $this->assertSame(count(AutomationRunRetention::TERMINAL), $pruned);

        foreach ($expected as [$session, $keepsHistory]) {
            $this->assertSame(
                $keepsHistory,
                $this->hasHistory($session),
                "{$session->run_kind->value} {$session->status->value} session finished {$session->updated_at}",
            );
            $this->assertTrue($this->exists($session), 'The session row always stays.');
        }
    }

    public function test_the_whole_history_goes_including_rows_newer_than_the_cutoff(): void
    {
        $session = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $this->now->subDays(100));
        $this->history($session, $this->now->subDays(1));

        $this->pruner()->prune($this->tenantId, $this->cutoff());

        $this->assertFalse($this->hasHistory($session));
    }

    public function test_a_session_exactly_at_the_cutoff_keeps_its_history(): void
    {
        $session = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $this->cutoff());

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, $this->cutoff()));
        $this->assertTrue($this->hasHistory($session));
    }

    public function test_another_tenants_sessions_in_the_same_schema_keep_their_history(): void
    {
        $foreign = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $this->now->subDays(400), (string) Str::uuid());

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, $this->cutoff()));
        $this->assertTrue($this->hasHistory($foreign));
    }

    public function test_a_finished_child_keeps_its_history_while_its_parent_is_going(): void
    {
        $old    = $this->now->subDays(400);
        $parent = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::PausedSubflow, $old);
        $child  = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $old, parentId: $parent->getKey());

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, $this->cutoff()));
        $this->assertTrue($this->hasHistory($child));
        $this->assertTrue($this->hasHistory($parent));
    }

    public function test_a_finished_child_of_a_finished_parent_loses_its_history_with_it(): void
    {
        $old    = $this->now->subDays(400);
        $parent = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $old);
        $child  = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $old, parentId: $parent->getKey());

        $this->assertSame(2, $this->pruner()->prune($this->tenantId, $this->cutoff()));
        $this->assertFalse($this->hasHistory($child));
        $this->assertFalse($this->hasHistory($parent));
    }

    public function test_a_finished_grandchild_keeps_its_history_while_the_root_is_going(): void
    {
        $old        = $this->now->subDays(400);
        $root       = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::WaitingInput, $old);
        $child      = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $old, parentId: $root->getKey());
        $grandchild = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $old, parentId: $child->getKey());

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, $this->cutoff()));
        $this->assertTrue($this->hasHistory($child));
        $this->assertTrue($this->hasHistory($grandchild));
    }

    public function test_a_young_parent_keeps_its_old_childs_history(): void
    {
        $parent = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $this->now->subDays(1));
        $child  = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $this->now->subDays(400), parentId: $parent->getKey());

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, $this->cutoff()));
        $this->assertTrue($this->hasHistory($child));
    }

    public function test_a_live_or_young_child_keeps_its_old_parents_history(): void
    {
        $old        = $this->now->subDays(400);
        $liveParent = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $old);
        $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::WaitingInput, $old, parentId: $liveParent->getKey());
        $youngParent = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $old);
        $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $this->now->subDays(1), parentId: $youngParent->getKey());

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, $this->cutoff()));
        $this->assertTrue($this->hasHistory($liveParent));
        $this->assertTrue($this->hasHistory($youngParent));
    }

    public function test_a_whole_finished_chain_of_three_levels_loses_its_history(): void
    {
        $old        = $this->now->subDays(400);
        $root       = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $old);
        $child      = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $old, parentId: $root->getKey());
        $grandchild = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Failed, $old, parentId: $child->getKey());

        $this->assertSame(3, $this->pruner()->prune($this->tenantId, $this->cutoff()));
        $this->assertFalse($this->hasHistory($root));
        $this->assertFalse($this->hasHistory($child));
        $this->assertFalse($this->hasHistory($grandchild));
    }

    public function test_a_chain_deeper_than_the_limit_is_kept(): void
    {
        $old    = $this->now->subDays(400);
        $root   = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $old);
        $parent = $root;

        for ($level = 0; $level <= SessionHistoryPruner::MAX_CHAIN_DEPTH; ++$level) {
            $parent = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $old, parentId: $parent->getKey());
        }

        $this->assertSame(0, $this->pruner()->prune($this->tenantId, $this->cutoff()));
        $this->assertTrue($this->hasHistory($root));
    }

    public function test_a_dry_run_counts_exactly_what_the_run_then_deletes(): void
    {
        $session = $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Ended, $this->now->subDays(400));
        $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::WaitingInput, $this->now->subDays(400));

        $this->assertSame(1, $this->pruner()->prune($this->tenantId, $this->cutoff(), dryRun: true));
        $this->assertTrue($this->hasHistory($session));

        $this->assertSame(1, $this->pruner()->prune($this->tenantId, $this->cutoff()));
        $this->assertFalse($this->hasHistory($session));
    }

    public function test_a_session_already_pruned_is_not_counted_again(): void
    {
        $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $this->now->subDays(400));

        $this->assertSame(1, $this->pruner()->prune($this->tenantId, $this->cutoff()));
        $this->assertSame(0, $this->pruner()->prune($this->tenantId, $this->cutoff()));
    }

    public function test_a_backlog_larger_than_a_batch_is_drained_and_capped_per_call(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $this->now->subDays(400 + $i));
        }

        $pruner = new SessionHistoryPruner(batchSize: 2, maxBatches: 2);

        $this->assertSame(4, $pruner->prune($this->tenantId, $this->cutoff()));
        $this->assertSame(1, $pruner->prune($this->tenantId, $this->cutoff()));
        $this->assertSame(0, FlowSessionHistoryEntry::query()->count());
    }

    public function test_a_dry_run_walks_past_the_first_batch(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->flowRun(FlowRunKind::Conversation, FlowSessionStatus::Completed, $this->now->subDays(400 + $i));
        }

        $this->assertSame(5, (new SessionHistoryPruner(batchSize: 2))->prune($this->tenantId, $this->cutoff(), dryRun: true));
    }

    private function cutoff(): CarbonImmutable
    {
        return $this->now->subDays(self::RETENTION_DAYS);
    }

    private function pruner(): SessionHistoryPruner
    {
        return $this->app->make(SessionHistoryPruner::class);
    }

    private function flowRun(
        FlowRunKind $kind,
        FlowSessionStatus $status,
        CarbonImmutable $finishedAt,
        ?string $tenantId = null,
        ?string $parentId = null,
    ): FlowSession {
        $tenantId ??= $this->tenantId;
        $contact = FlowRunKind::Conversation === $kind ? Contact::factory()->forTenant($tenantId)->create() : null;

        $session = FlowSession::query()->create([
            'tenant_id'             => $tenantId,
            'contact_id'            => $contact?->getKey(),
            'flow_definition_id'    => $this->definition->getKey(),
            'flow_version'          => 1,
            'status'                => $status,
            'run_kind'              => $kind,
            'state'                 => [],
            'parent_session_id'     => $parentId,
            'parent_resume_node_id' => null !== $parentId ? 'sub' : null,
        ]);

        FlowSession::query()->whereKey($session->getKey())->toBase()->update([
            'created_at' => $finishedAt->subHour(),
            'updated_at' => $finishedAt,
        ]);

        $this->history($session, $finishedAt->subMinutes(30));
        $this->history($session, $finishedAt->subMinute());

        return $session->refresh();
    }

    private function history(FlowSession $session, CarbonImmutable $at): void
    {
        FlowSessionHistoryEntry::query()->create([
            'tenant_id'  => $session->tenant_id,
            'session_id' => $session->getKey(),
            'node_id'    => 'end',
            'event_type' => 'node_entered',
            'created_at' => $at,
        ]);
    }

    private function exists(FlowSession $session): bool
    {
        return FlowSession::query()->whereKey($session->getKey())->exists();
    }

    private function hasHistory(FlowSession $session): bool
    {
        return FlowSessionHistoryEntry::query()->where('session_id', $session->getKey())->exists();
    }
}
