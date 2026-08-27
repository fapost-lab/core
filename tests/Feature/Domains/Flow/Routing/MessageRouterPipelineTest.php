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
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Routing\MessageRouter;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
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
        $registry->register(new RecordingTestHandler());
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

    public function test_active_session_race_drops_silently_and_does_not_advance(): void
    {
        // Two workers may briefly observe an Active session right after a peer
        // released the lock. The router rejects that race silently — no busy
        // notice, no execution. (Paused / paused_subflow follow the same
        // silent-drop / route-to-child rules but aren't visible through the
        // current findActiveForContact filter; they're covered in unit tests.)
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
        $channel             = Channel::factory()->make([
            'tenant_id'    => $tenantId,
            'assistant_id' => $assistant->getKey(),
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'noop-token',
        ]);
        $channel->id         = (string) Str::uuid();
        $channel->exists     = true;

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
