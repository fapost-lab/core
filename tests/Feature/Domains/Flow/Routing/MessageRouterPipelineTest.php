<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Routing;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Live\FlowActivityChanged;
use App\Domains\Flow\Live\FlowActivityWatchers;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Routing\MessageRouter;
use App\Domains\Flow\Routing\SessionRoutingDecision;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * End-to-end tests for the routing pipeline. Exercises {@see MessageRouter}
 * with real container wiring (engine, persister, lock, sessions, analytics)
 * — only the outbound channel is bypassed by registering a flow that ends
 * before any send_message node fires.
 *
 * Each scenario seeds tenant + assistant + contact + channel, defines a flow,
 * dispatches an inbound message, then asserts on the persisted FlowSession.
 */
final class MessageRouterPipelineTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A handler that only writes to flow.* state — no outbound channel needed.
        $registry = $this->app->make(NodeHandlerRegistry::class);
        $registry->register(RecordingTestHandler::class);
        $registry->register(DelayedResumeTestHandler::class);
    }

    public function test_unknown_message_starts_default_flow_and_runs_to_end_node(): void
    {
        [$tenantId, $assistant, $contact, $channel] = $this->seedTenantWithFlow(
            nodes: [
                ['id' => 'rec-1', 'type' => 'recording_test', 'version' => 1, 'config' => ['marker' => 'first']],
                ['id' => 'end-1', 'type' => 'end',            'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'rec-1', 'to' => 'end-1', 'handle' => 'default'],
            ],
        );

        $router = $this->app->make(MessageRouter::class);

        $outcome = $router->route(
            contact: $contact,
            message: $this->incoming('hello'),
            assistant: $assistant,
            channel: $channel,
        );

        $this->assertSame('executed', $outcome->kind);

        /** @var FlowSession|null $session */
        $session = FlowSession::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contact->getKey())
            ->latest('created_at')
            ->first();

        $this->assertNotNull($session);
        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertSame(EndStatus::Success->value, $session->end_status);
        $this->assertNull($session->current_node_id);
        $this->assertSame('first', $session->state['flow']['marker'] ?? null);
    }

    public function test_global_reset_command_terminates_active_session_without_executing_flow(): void
    {
        [$tenantId, $assistant, $contact, $channel] = $this->seedTenantWithFlow(
            nodes: [
                ['id' => 'rec-1', 'type' => 'recording_test', 'version' => 1, 'config' => ['marker' => 'persisted']],
                ['id' => 'end-1', 'type' => 'end',            'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'rec-1', 'to' => 'end-1', 'handle' => 'default'],
            ],
        );

        // First, drive a regular message so an active session exists … then
        // re-create a fresh session directly because our test flow ends in one
        // pass. We need a session that's still active when /reset arrives.
        $session = FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => FlowDefinition::query()->where('tenant_id', $tenantId)->value('id'),
            'flow_version'       => 1,
            'current_node_id'    => 'rec-1',
            'state'              => [],
            'status'             => FlowSessionStatus::WaitingInput,
            'version'            => 1,
        ]);

        $router = $this->app->make(MessageRouter::class);

        $outcome = $router->route(
            contact: $contact,
            message: $this->incoming('/reset'),
            assistant: $assistant,
            channel: $channel,
        );

        $this->assertSame('command', $outcome->kind);
        $this->assertSame('/reset', $outcome->command);

        $session->refresh();
        $this->assertSame(FlowSessionStatus::TerminatedByUser, $session->status);
        $this->assertNull($session->current_node_id);
    }

    public function test_global_reset_command_announces_the_terminated_session_to_live_screens(): void
    {
        [$tenantId, $assistant, $contact, $channel] = $this->seedTenantWithFlow(
            nodes: [
                ['id' => 'rec-1', 'type' => 'recording_test', 'version' => 1, 'config' => ['marker' => 'persisted']],
                ['id' => 'end-1', 'type' => 'end',            'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'rec-1', 'to' => 'end-1', 'handle' => 'default'],
            ],
        );

        FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => FlowDefinition::query()->where('tenant_id', $tenantId)->value('id'),
            'flow_version'       => 1,
            'current_node_id'    => 'rec-1',
            'state'              => [],
            'status'             => FlowSessionStatus::WaitingInput,
            'version'            => 1,
        ]);

        // The terminating write is a query, not a model save: it announces itself explicitly.
        config(['broadcasting.default' => 'pusher']);
        $this->app->make(FlowActivityWatchers::class)->watch($tenantId, (string) $assistant->getKey());
        Event::fake([FlowActivityChanged::class]);

        $this->app->make(MessageRouter::class)->route(
            contact: $contact,
            message: $this->incoming('/reset'),
            assistant: $assistant,
            channel: $channel,
        );

        Event::assertDispatched(FlowActivityChanged::class, static fn (FlowActivityChanged $event): bool => $event->assistantId === (string) $assistant->getKey());
    }

    public function test_global_reset_command_terminates_a_paused_session_too(): void
    {
        [$tenantId, $assistant, $contact, $channel] = $this->seedTenantWithFlow(
            nodes: [
                ['id' => 'd1',    'type' => 'delayed_resume_test', 'version' => 1, 'config' => ['seconds' => 60]],
                ['id' => 'end-1', 'type' => 'end',                 'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'd1', 'to' => 'end-1', 'handle' => 'default'],
            ],
        );

        // Parked on `paused` with a `resume_at` far in the future — /reset must
        // still be able to break in, even though the routing pipeline itself
        // would answer any ordinary message with a busy notice.
        $session = FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => FlowDefinition::query()->where('tenant_id', $tenantId)->value('id'),
            'flow_version'       => 1,
            'current_node_id'    => 'd1',
            'state'              => [
                'system' => ['delayed' => ['d1' => ['resume_at' => now()->addHour()->toAtomString()]]],
            ],
            'status'  => FlowSessionStatus::Paused,
            'version' => 1,
        ]);

        $router = $this->app->make(MessageRouter::class);

        $outcome = $router->route(
            contact: $contact,
            message: $this->incoming('/reset'),
            assistant: $assistant,
            channel: $channel,
        );

        $this->assertSame('command', $outcome->kind);
        $this->assertSame('/reset', $outcome->command);

        $session->refresh();
        $this->assertSame(FlowSessionStatus::TerminatedByUser, $session->status);
        $this->assertNull($session->current_node_id);
    }

    public function test_active_session_race_drops_silently_and_does_not_advance(): void
    {
        // Two workers may briefly observe an Active session right after a peer
        // released the lock. The router rejects that race silently — no busy
        // notice, no execution.
        [$tenantId, $assistant, $contact, $channel] = $this->seedTenantWithFlow(
            nodes: [
                ['id' => 'rec-1', 'type' => 'recording_test', 'version' => 1, 'config' => []],
                ['id' => 'end-1', 'type' => 'end',            'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'rec-1', 'to' => 'end-1', 'handle' => 'default'],
            ],
        );

        $session = FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => FlowDefinition::query()->where('tenant_id', $tenantId)->value('id'),
            'flow_version'       => 1,
            'current_node_id'    => 'rec-1',
            'state'              => [],
            'status'             => FlowSessionStatus::Active,
            'version'            => 1,
        ]);

        $router = $this->app->make(MessageRouter::class);

        $outcome = $router->route(
            contact: $contact,
            message: $this->incoming('hi'),
            assistant: $assistant,
            channel: $channel,
        );

        $this->assertTrue($outcome->wasDropped());
        $this->assertSame('drop_silent', $outcome->reason);

        $session->refresh();
        $this->assertSame(FlowSessionStatus::Active, $session->status);
        $this->assertSame('rec-1', $session->current_node_id);
    }

    public function test_paused_session_before_resume_at_drops_busy_and_does_not_wake_the_node(): void
    {
        [$tenantId, $assistant, $contact, $channel] = $this->seedTenantWithFlow(
            nodes: [
                ['id' => 'd1',     'type' => 'delayed_resume_test', 'version' => 1, 'config' => ['seconds' => 60]],
                ['id' => 'end-1', 'type' => 'end',                 'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'd1', 'to' => 'end-1', 'handle' => 'default'],
            ],
        );

        $router = $this->app->make(MessageRouter::class);

        $router->route(
            contact: $contact,
            message: $this->incoming('start'),
            assistant: $assistant,
            channel: $channel,
        );

        $session = FlowSession::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contact->getKey())
            ->latest('created_at')
            ->firstOrFail();

        $this->assertSame(FlowSessionStatus::Paused, $session->status);
        $this->assertSame('d1', $session->current_node_id);
        $resumeAt = $session->state['system']['delayed']['d1']['resume_at'] ?? null;
        $this->assertNotNull($resumeAt, 'The engine-owned resume marker must be set.');

        // Second message arrives well before resume_at — the busy reply must
        // fire and the node must not be woken or the flow re-routed.
        $outcome = $router->route(
            contact: $contact,
            message: $this->incoming('hello'),
            assistant: $assistant,
            channel: $channel,
        );

        $this->assertTrue($outcome->wasDropped());
        $this->assertSame('drop_busy', $outcome->reason);

        $session->refresh();
        $this->assertSame(FlowSessionStatus::Paused, $session->status);
        $this->assertSame('d1', $session->current_node_id);
        $this->assertSame($resumeAt, $session->state['system']['delayed']['d1']['resume_at'] ?? null);

        $this->assertSame(
            1,
            FlowSession::query()->where('tenant_id', $tenantId)->where('contact_id', $contact->getKey())->count(),
            'A busy drop must not start a second session for the same contact.',
        );
    }

    public function test_paused_session_after_resume_at_wakes_the_node_then_routes_the_message(): void
    {
        [$tenantId, $assistant, $contact, $channel] = $this->seedTenantWithFlow(
            nodes: [
                ['id' => 'd1',    'type' => 'delayed_resume_test', 'version' => 1, 'config' => ['seconds' => 60]],
                ['id' => 'end-1', 'type' => 'end',                 'version' => 1, 'config' => ['status' => 'success']],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'd1', 'to' => 'end-1', 'handle' => 'default'],
            ],
        );

        $router = $this->app->make(MessageRouter::class);

        $router->route(
            contact: $contact,
            message: $this->incoming('start'),
            assistant: $assistant,
            channel: $channel,
        );

        $session = FlowSession::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contact->getKey())
            ->latest('created_at')
            ->firstOrFail();

        $this->assertSame(FlowSessionStatus::Paused, $session->status);

        $this->travel(61)->seconds();

        // resume_at has passed: the router must wake d1 inline (resumedAfterDelay
        // parks it back on waiting_input without consuming this message) and then
        // route this very message against the woken session's new state.
        $outcome = $router->route(
            contact: $contact,
            message: $this->incoming('hello'),
            assistant: $assistant,
            channel: $channel,
        );

        $this->assertSame('executed', $outcome->kind);

        $session->refresh();
        $this->assertSame(FlowSessionStatus::Ended, $session->status);
        $this->assertSame(EndStatus::Success->value, $session->end_status);
        $this->assertNull($session->current_node_id);
        // The marker written for d1 must be cleared once it completed.
        $this->assertNull($session->state['system']['delayed']['d1'] ?? null);

        $this->assertSame(
            1,
            FlowSession::query()->where('tenant_id', $tenantId)->where('contact_id', $contact->getKey())->count(),
            'Waking the node and routing the message must not start a second session.',
        );
    }

    public function test_inbound_message_goes_to_the_waiting_child_while_the_parent_stays_paused_subflow(): void
    {
        // The repository never returns a paused_subflow parent: the waiting
        // child is the contact's selected session, so the message resumes it.
        [$tenantId, $assistant, $contact, $channel] = $this->seedTenantWithFlow(
            nodes: [
                ['id' => 'in-1', 'type' => 'input', 'version' => 1, 'config' => ['save_to' => 'flow.answer']],
                ['id' => 'in-2', 'type' => 'input', 'version' => 1, 'config' => ['save_to' => 'flow.second']],
            ],
            edges: [
                ['id' => 'e1', 'from' => 'in-1', 'to' => 'in-2', 'handle' => 'default'],
            ],
        );

        $definitionId = FlowDefinition::query()->where('tenant_id', $tenantId)->value('id');

        $parent = FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $definitionId,
            'flow_version'       => 1,
            'current_node_id'    => 'in-1',
            'state'              => [],
            'status'             => FlowSessionStatus::PausedSubflow,
            'version'            => 1,
        ]);

        $child = FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $definitionId,
            'flow_version'       => 1,
            'current_node_id'    => 'in-1',
            'state'              => [],
            'status'             => FlowSessionStatus::WaitingInput,
            'parent_session_id'  => $parent->getKey(),
            'version'            => 1,
        ]);

        // Make the parent the most recently updated row, so a repository that ever started
        // selecting paused_subflow sessions would pick it over the child and fail this test.
        $this->travel(1)->seconds();
        $parent->touch();

        $router = $this->app->make(MessageRouter::class);

        $outcome = $router->route(
            contact: $contact,
            message: $this->incoming('hello'),
            assistant: $assistant,
            channel: $channel,
        );

        $this->assertSame('executed', $outcome->kind);
        $this->assertSame(SessionRoutingDecision::ResumeWaiting, $outcome->decision);

        $child->refresh();
        $this->assertSame(FlowSessionStatus::WaitingInput, $child->status);
        $this->assertSame('in-2', $child->current_node_id, 'The child must advance past its input node.');
        $this->assertSame('hello', $child->state['flow']['answer'] ?? null);

        $parent->refresh();
        $this->assertSame(FlowSessionStatus::PausedSubflow, $parent->status);
        $this->assertSame('in-1', $parent->current_node_id);
        $this->assertSame([], $parent->state);

        $this->assertSame(
            2,
            FlowSession::query()->where('tenant_id', $tenantId)->where('contact_id', $contact->getKey())->count(),
            'Routing to the child must not start a third session.',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     * @return array{0: string, 1: Assistant, 2: Contact, 3: Channel}
     */
    private function seedTenantWithFlow(array $nodes, array $edges): array
    {
        $tenantId = (string) Str::uuid();
        $flowId   = (string) Str::uuid();

        // Channel observer reads TenantContext on save — make sure it's set
        // before any tenant-scoped model in this seed gets persisted.
        $this->app->make(TenantContextInterface::class)->set(
            new RuntimeTenant(id: $tenantId, schemaName: 'main'),
        );

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => $flowId,
            'version'   => 1,
            'name'      => 'E2E test flow',
            'nodes'     => $nodes,
            'edges'     => $edges,
            'is_active' => true,
        ]);

        $assistant = Assistant::factory()->create([
            'tenant_id'        => $tenantId,
            'default_flow_id'  => $flowId,
            'default_language' => 'en',
        ]);

        $contact = Contact::factory()->forTenant($tenantId)->create();

        // In-memory Channel — MessageRouter only reads `type` and `token`,
        // and the typing indicator service is a best-effort no-op when no
        // sender is registered for the channel type. Avoids ChannelObserver's
        // Telegram API call on real persistence.
        $channel = Channel::factory()->make([
            'tenant_id'    => $tenantId,
            'assistant_id' => $assistant->getKey(),
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'noop-token',
        ]);
        $channel->id     = (string) Str::uuid();
        $channel->exists = true;

        $this->app->make(CurrentAssistantInterface::class)->set($assistant);

        return [$tenantId, $assistant, $contact, $channel];
    }

    private function incoming(string $text): IncomingMessage
    {
        return new IncomingMessage(
            updateId: 'upd-' . Str::uuid()->toString(),
            externalUserId: 'ext-user-1',
            externalChatId: 'ext-chat-1',
            text: $text,
            type: IncomingMessageType::Text,
            platform: 'telegram',
        );
    }
}

/**
 * Lightweight handler that records its `marker` config into flow.state for
 * pipeline assertions. Pure — no outbound channel use, no DB I/O.
 */
final class RecordingTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'recording_test';
    }

    public function version(): int
    {
        return 1;
    }

    public function supportedVersions(): array
    {
        return [1];
    }

    public function label(): string
    {
        return 'Recording Test';
    }

    public function category(): string
    {
        return 'Test';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $marker = $nodeConfig['config']['marker'] ?? null;

        $changes = [];
        if (is_string($marker) && '' !== $marker) {
            $changes['flow.marker'] = $marker;
        }

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'default',
            stateChanges: $changes,
        );
    }
}

/**
 * Test double for the `delayed(resumeAt: ...)` contract: on a first visit it
 * parks with a timed resume; once the engine wakes it
 * ({@see NodeExecutionContext::$resumedAfterDelay}) it parks like `waiting()`
 * instead of consuming the incoming message itself — matching the routing
 * pipeline's "wake, then route" sequencing — so the actual inbound message
 * that triggered the wake-up is the one that advances it to `default`.
 */
final class DelayedResumeTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'delayed_resume_test';
    }

    public function version(): int
    {
        return 1;
    }

    public function supportedVersions(): array
    {
        return [1];
    }

    public function label(): string
    {
        return 'Delayed Resume Test';
    }

    public function category(): string
    {
        return 'Test';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        if ($context->resumedAfterDelay) {
            return NodeExecutionResult::waiting();
        }

        if (null !== $context->incoming) {
            return NodeExecutionResult::executed();
        }

        $seconds = (int) ($nodeConfig['config']['seconds'] ?? 60);

        return NodeExecutionResult::delayed(resumeAt: Carbon::now()->addSeconds($seconds));
    }
}
