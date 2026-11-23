<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Enums\RerunRefusal;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Services\AutomationRunRerunService;
use App\Domains\Flow\Webhook\WebhookReply;
use App\Domains\Flow\Webhook\WebhookReplyStoreInterface;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Jobs\Flow\StartAutomationRunJob;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * Re-running a failed automation run from the start: the trigger payload kept at the start, the refusals, the path
 * through the trigger's start job, the one run a repeated request makes, and the audit entry.
 */
#[Group('redis')]
final class AutomationRunRerunTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());
        $this->app->make(NodeHandlerRegistry::class)->register(RerunFailingTestHandler::class);
        $this->assistant = Assistant::factory()->create();
    }

    public function test_a_root_automation_run_keeps_its_trigger_payload_and_a_conversation_does_not(): void
    {
        [$flowId, $triggerId] = $this->automation([$this->end()]);
        $payload              = ['flow' => ['event' => ['order' => ['id' => 7]]]];

        $this->startRun($flowId, $triggerId, 'evt-1', $payload);

        $run = FlowSession::query()->where('fire_key', 'evt-1')->sole();
        $this->assertSame($payload, $run->trigger_payload);

        $conversation = $this->runSession(FlowSessionStatus::Failed, [
            'run_kind'   => FlowRunKind::Conversation,
            'contact_id' => Contact::factory()->create()->getKey(),
        ], $flowId);
        $this->assertNull($conversation->refresh()->trigger_payload);
    }

    public function test_a_subflow_child_keeps_no_payload_of_its_own(): void
    {
        $childFlowId          = $this->definition([$this->end()]);
        [$flowId, $triggerId] = $this->automation([
            ['id' => 'sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $childFlowId, 'timeout' => 'PT1H']],
            $this->end(),
        ], [['id' => 'e1', 'from' => 'sub', 'to' => 'end', 'handle' => 'success']]);

        $this->startRun($flowId, $triggerId, 'evt-1', ['flow' => ['event' => ['n' => 1]]]);

        $child = FlowSession::query()->whereNotNull('parent_session_id')->sole();
        $this->assertNull($child->trigger_payload);
        $this->assertSame(['flow' => ['event' => ['n' => 1]]], FlowSession::query()->whereNull('parent_session_id')->sole()->trigger_payload);
    }

    public function test_a_re_run_starts_with_the_payload_the_failed_run_started_with_not_the_state_it_changed(): void
    {
        [$flowId, $triggerId] = $this->automation([
            ['id' => 'overwrite', 'type' => 'assign', 'version' => 1, 'config' => ['target' => 'flow', 'key' => 'webhook.body', 'value' => 'overwritten']],
            ['id' => 'boom', 'type' => 'rerun_failing_test', 'version' => 1, 'config' => []],
            $this->end(),
        ]);
        $payload = ['flow' => ['webhook' => ['method' => 'POST', 'body' => ['order' => 42], 'delivery_id' => 'd-1']]];

        $this->startRun($flowId, $triggerId, 'wh:d-1', $payload);
        $source = FlowSession::query()->where('fire_key', 'wh:d-1')->sole();
        $this->assertSame(FlowSessionStatus::Failed, $source->status);
        $this->assertSame('overwritten', $source->state['flow']['webhook']['body']);

        $outcome = $this->service()->rerun($source, 1);

        $this->assertNull($outcome->refusal);
        $this->assertSame('rerun:' . $source->getKey() . ':1', $outcome->fireKey);
        $job = Queue::pushed(StartAutomationRunJob::class)->sole();
        $this->assertSame([self::TENANT_ID, $flowId, $triggerId, $outcome->fireKey, (string) $this->assistant->getKey(), 0], [
            $job->tenantId, $job->flowId, $job->triggerId, $job->fireKey, $job->assistantId, $job->chainDepth,
        ]);
        // jsonb does not keep the order of keys, so the payload is compared as a map, not as a sequence.
        $this->assertEquals($payload, $job->initialState);

        $this->runJob($job);

        $rerun = FlowSession::query()->where('fire_key', $outcome->fireKey)->sole();
        $this->assertSame(['order' => 42], $rerun->trigger_payload['flow']['webhook']['body']);
        $this->assertSame('d-1', $rerun->trigger_payload['flow']['webhook']['delivery_id']);
        $this->assertSame((string) $this->assistant->getKey(), $rerun->assistant_id);
        // The source run is left as it was.
        $this->assertSame(FlowSessionStatus::Failed, $source->refresh()->status);
        $this->assertSame('wh:d-1', $source->fire_key);
    }

    public function test_a_re_run_of_an_event_run_keeps_its_place_in_the_chain_of_events(): void
    {
        [$flowId, $triggerId] = $this->automation([$this->end()]);
        $source               = $this->failedRun($flowId, $triggerId, ['flow' => ['event' => ['tag' => 'vip']]], ['event_chain_depth' => 3]);

        $this->service()->rerun($source, 1);

        $job = Queue::pushed(StartAutomationRunJob::class)->sole();
        $this->assertSame(3, $job->chainDepth);
        $this->assertSame(['flow' => ['event' => ['tag' => 'vip']]], $job->initialState);

        $this->runJob($job);

        $this->assertSame(3, FlowSession::query()->where('fire_key', $job->fireKey)->sole()->event_chain_depth);
    }

    public function test_a_re_run_runs_the_latest_active_version_of_the_flow(): void
    {
        [$flowId, $triggerId] = $this->automation([$this->end()]);
        $source               = $this->failedRun($flowId, $triggerId);
        $this->publishVersion($flowId, 2);

        $outcome = $this->service()->check($source);
        $this->assertSame(2, $outcome->targetVersion);

        $this->service()->rerun($source, 1);
        $this->runJob(Queue::pushed(StartAutomationRunJob::class)->sole());

        $this->assertSame(2, FlowSession::query()->where('fire_key', 'like', 'rerun:%')->sole()->flow_version);
    }

    public function test_a_run_whose_trigger_is_off_or_gone_or_whose_flow_has_no_active_version_is_refused(): void
    {
        [$flowId, $triggerId] = $this->automation([$this->end()]);
        $source               = $this->failedRun($flowId, $triggerId);

        FlowTrigger::query()->whereKey($triggerId)->update(['is_active' => false]);
        $this->assertRefused(RerunRefusal::TriggerGone, $source);

        FlowTrigger::query()->whereKey($triggerId)->delete();
        $this->assertRefused(RerunRefusal::TriggerGone, $source);

        [$flowId, $triggerId] = $this->automation([$this->end()]);
        $source               = $this->failedRun($flowId, $triggerId);
        FlowDefinition::query()->where('flow_id', $flowId)->update(['is_active' => false]);
        $this->assertRefused(RerunRefusal::NoActiveVersion, $source);

        [$flowId, $triggerId] = $this->automation([$this->end()]);
        $source               = $this->failedRun($flowId, $triggerId);
        FlowDraft::query()->where('flow_id', $flowId)->delete();
        $this->assertRefused(RerunRefusal::TriggerGone, $source);

        Queue::assertNotPushed(StartAutomationRunJob::class);
        $this->assertSame(0, ActivityEntry::query()->where('description', 'flow.run_rerun')->count());
    }

    public function test_a_run_of_a_flow_switched_off_in_the_flows_list_is_refused_until_it_is_turned_on(): void
    {
        [$flowId, $triggerId] = $this->automation([$this->end()]);
        $source               = $this->failedRun($flowId, $triggerId);
        FlowDraft::query()->where('flow_id', $flowId)->update(['is_active' => false]);

        $this->assertRefused(RerunRefusal::FlowOff, $source);
        Queue::assertNotPushed(StartAutomationRunJob::class);
        $this->assertSame(0, ActivityEntry::query()->where('description', 'flow.run_rerun')->count());

        FlowDraft::query()->where('flow_id', $flowId)->update(['is_active' => true]);

        $this->assertFalse($this->service()->check($source)->isRefused());
    }

    public function test_only_a_failed_root_automation_run_with_a_kept_payload_is_re_run(): void
    {
        [$flowId, $triggerId] = $this->automation([$this->end()]);
        $parent               = $this->failedRun($flowId, $triggerId);

        $this->assertRefused(RerunRefusal::NotAnAutomation, $this->failedRun($flowId, $triggerId, attributes: [
            'run_kind'   => FlowRunKind::Conversation,
            'contact_id' => Contact::factory()->create()->getKey(),
        ]));
        $this->assertRefused(RerunRefusal::NotARoot, $this->failedRun($flowId, $triggerId, attributes: [
            'parent_session_id' => $parent->getKey(),
            'fire_key'          => null,
        ]));
        $this->assertRefused(RerunRefusal::NotFailed, $this->failedRun($flowId, $triggerId, attributes: ['status' => FlowSessionStatus::Active]));
        $this->assertRefused(RerunRefusal::NotFailed, $this->failedRun($flowId, $triggerId, attributes: ['status' => FlowSessionStatus::Completed]));
        $this->assertRefused(RerunRefusal::NoPayload, $this->failedRun($flowId, $triggerId, attributes: ['trigger_payload' => null]));

        Queue::assertNotPushed(StartAutomationRunJob::class);
    }

    public function test_asking_twice_before_the_re_run_exists_starts_one_run(): void
    {
        [$flowId, $triggerId] = $this->automation([$this->end()]);
        $source               = $this->failedRun($flowId, $triggerId);

        $this->assertSame(1, $this->service()->check($source)->attempt);

        $first  = $this->service()->rerun($source, 1);
        $second = $this->service()->rerun($source, 1);
        $this->assertSame($first->fireKey, $second->fireKey);

        foreach (Queue::pushed(StartAutomationRunJob::class) as $job) {
            $this->runJob($job);
        }

        $this->assertSame(1, FlowSession::query()->where('fire_key', 'like', 'rerun:%')->count());
    }

    public function test_a_request_replayed_after_its_re_run_started_is_refused_and_the_next_attempt_starts_another(): void
    {
        [$flowId, $triggerId] = $this->automation([$this->end()]);
        $source               = $this->failedRun($flowId, $triggerId);

        $this->service()->rerun($source, 1);
        $this->runJob(Queue::pushed(StartAutomationRunJob::class)->sole());

        // A second tab, a double click that came late, a replayed POST: the attempt it carries has its run already.
        $this->assertSame(RerunRefusal::AlreadyRerun, $this->service()->rerun($source, 1)->refusal);
        $this->assertSame(RerunRefusal::AlreadyRerun, $this->service()->rerun($source, 5)->refusal);
        Queue::assertPushed(StartAutomationRunJob::class, 1);

        $this->assertSame(2, $this->service()->check($source)->attempt);
        $this->assertSame('rerun:' . $source->getKey() . ':2', $this->service()->rerun($source, 2)->fireKey);
        $this->assertSame(1, ActivityEntry::query()->where('description', 'flow.run_rerun')->where('properties->fire_key', 'rerun:' . $source->getKey() . ':2')->count());
        $this->assertSame(2, ActivityEntry::query()->where('description', 'flow.run_rerun')->count());
    }

    public function test_a_lower_re_run_deleted_by_retention_does_not_make_the_next_attempt_collide(): void
    {
        [$flowId, $triggerId] = $this->automation([$this->end()]);
        $source               = $this->failedRun($flowId, $triggerId);
        $first                = $this->failedRun($flowId, $triggerId, attributes: ['fire_key' => 'rerun:' . $source->getKey() . ':1', 'status' => FlowSessionStatus::Completed]);
        $this->failedRun($flowId, $triggerId, attributes: ['fire_key' => 'rerun:' . $source->getKey() . ':2']);

        // Retention deletes the succeeded re-run first, while the failed one is kept.
        $first->delete();

        $this->assertSame(3, $this->service()->check($source)->attempt);

        $outcome = $this->service()->rerun($source, 3);
        $this->runJob(Queue::pushed(StartAutomationRunJob::class)->sole());

        $this->assertSame('rerun:' . $source->getKey() . ':3', $outcome->fireKey);
        $this->assertSame(FlowSessionStatus::Ended, FlowSession::query()->where('fire_key', $outcome->fireKey)->sole()->status);
    }

    public function test_a_re_run_of_a_webhook_run_answers_no_sender(): void
    {
        $replies = new RerunRecordingReplyStore();
        $this->app->instance(WebhookReplyStoreInterface::class, $replies);

        [$flowId, $triggerId] = $this->automation([
            ['id' => 'respond', 'type' => 'webhook_response', 'version' => 1, 'config' => ['status' => 200, 'body_type' => 'text', 'text' => 'hi']],
            $this->end(),
        ]);
        $source = $this->failedRun($flowId, $triggerId, ['flow' => ['webhook' => ['body' => []]]], ['fire_key' => 'wh:d-9']);

        $this->service()->rerun($source, 1);
        $this->runJob(Queue::pushed(StartAutomationRunJob::class)->sole());

        $this->assertSame(FlowSessionStatus::Ended, FlowSession::query()->where('fire_key', 'like', 'rerun:%')->sole()->status);
        $this->assertSame([], $replies->kept);
    }

    public function test_a_queued_re_run_is_recorded_in_the_audit_log(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);
        $this->actingAs($admin);

        [$flowId, $triggerId] = $this->automation([$this->end()]);
        $source               = $this->failedRun($flowId, $triggerId);

        $outcome = $this->service()->rerun($source, 1);

        $entry = ActivityEntry::query()->where('description', 'flow.run_rerun')->sole();
        $this->assertSame('flows', $entry->log_name);
        $this->assertSame('run_rerun', $entry->event);
        $this->assertSame((string) $source->getKey(), (string) $entry->subject_id);
        $this->assertSame((string) $admin->getKey(), (string) $entry->causer_id);
        $this->assertSame((string) $this->assistant->getKey(), $entry->assistant_id);
        $this->assertSame((string) $source->getKey(), $entry->properties['session_id']);
        $this->assertSame($outcome->fireKey, $entry->properties['fire_key']);
        $this->assertSame(1, $entry->properties['flow_version']);
    }

    private function service(): AutomationRunRerunService
    {
        return $this->app->make(AutomationRunRerunService::class);
    }

    private function assertRefused(RerunRefusal $refusal, FlowSession $source): void
    {
        $this->assertSame($refusal, $this->service()->check($source)->refusal);
        $this->assertSame($refusal, $this->service()->rerun($source, 1)->refusal);
    }

    /**
     * Run a start job as the queue would; a node failure surfaces to the job and leaves the run failed.
     */
    private function runJob(StartAutomationRunJob $job): void
    {
        try {
            $this->app->call([$job, 'handle']);
        } catch (RuntimeException $exception) {
            $this->assertSame('node failed', $exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function startRun(string $flowId, string $triggerId, string $fireKey, array $payload): void
    {
        $this->runJob(new StartAutomationRunJob(
            tenantId: self::TENANT_ID,
            flowId: $flowId,
            triggerId: $triggerId,
            fireKey: $fireKey,
            initialState: $payload,
            assistantId: (string) $this->assistant->getKey(),
        ));
    }

    /**
     * A failed root automation run of the trigger, as the engine leaves one.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $attributes
     */
    private function failedRun(string $flowId, string $triggerId, array $payload = ['flow' => ['event' => ['n' => 1]]], array $attributes = []): FlowSession
    {
        return $this->runSession(FlowSessionStatus::Failed, [
            'trigger_id'      => $triggerId,
            'fire_key'        => (string) Str::ulid(),
            'trigger_payload' => $payload,
            'state'           => $payload,
            ...$attributes,
        ], $flowId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function runSession(FlowSessionStatus $status, array $attributes, string $flowId): FlowSession
    {
        $definition = FlowDefinition::query()->where('flow_id', $flowId)->orderBy('version')->firstOrFail();

        return FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $this->assistant->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => $definition->version,
            'current_node_id'    => 'end',
            'state'              => [],
            'status'             => $status,
            'run_kind'           => FlowRunKind::Automation,
            'version'            => 1,
            ...$attributes,
        ]);
    }

    /**
     * A flow with its draft, its first version and a live event trigger.
     *
     * @param  list<array<string, mixed>>       $nodes
     * @param  list<array<string, mixed>>|null  $edges
     *
     * @return array{string, string} the flow id and the trigger id
     */
    private function automation(array $nodes, ?array $edges = null): array
    {
        $flowId = $this->definition($nodes, $edges);

        FlowDraft::factory()->create(['flow_id' => $flowId, 'assistant_id' => $this->assistant->getKey(), 'name' => 'Automation']);

        $trigger = FlowTrigger::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $this->assistant->getKey(),
            'flow_id'      => $flowId,
            'type'         => 'event',
            'is_active'    => true,
            'priority'     => 0,
            'config'       => ['event_name' => 'order.paid'],
        ]);

        return [$flowId, (string) $trigger->getKey()];
    }

    /**
     * @param  list<array<string, mixed>>       $nodes
     * @param  list<array<string, mixed>>|null  $edges  null links the nodes in a row through `default`
     */
    private function definition(array $nodes, ?array $edges = null, ?string $flowId = null, int $version = 1): string
    {
        if (null === $edges) {
            $edges = [];

            for ($i = 0; $i < count($nodes) - 1; ++$i) {
                $edges[] = ['id' => "e{$i}", 'from' => $nodes[$i]['id'], 'to' => $nodes[$i + 1]['id'], 'handle' => 'default'];
            }
        }

        $flowId ??= (string) Str::uuid();

        FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => $flowId,
            'version'   => $version,
            'name'      => 'Automation',
            'nodes'     => $nodes,
            'edges'     => $edges,
            'is_active' => true,
        ]);

        return $flowId;
    }

    /**
     * A newer version of the flow goes live, as a publish leaves it: one active version per flow.
     */
    private function publishVersion(string $flowId, int $version): void
    {
        FlowDefinition::query()->where('flow_id', $flowId)->update(['is_active' => false]);
        $this->definition([$this->end()], null, $flowId, $version);
    }

    /**
     * @return array<string, mixed>
     */
    private function end(): array
    {
        return ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']];
    }
}

/**
 * A node that always throws, so the run fails on it.
 */
final class RerunFailingTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'rerun_failing_test';
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
        return 'Failing probe';
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        throw new RuntimeException('node failed');
    }
}

/**
 * Records every answer a run keeps for a waiting webhook sender.
 */
final class RerunRecordingReplyStore implements WebhookReplyStoreInterface
{
    /** @var list<string> */
    public array $kept = [];

    public function keep(string $tenantId, string $triggerId, string $fireKey, WebhookReply $reply): bool
    {
        $this->kept[] = $fireKey;

        return true;
    }

    public function await(string $tenantId, string $triggerId, string $fireKey, int $seconds): ?WebhookReply
    {
        return null;
    }

    public function find(string $tenantId, string $triggerId, string $fireKey): ?WebhookReply
    {
        return null;
    }
}
