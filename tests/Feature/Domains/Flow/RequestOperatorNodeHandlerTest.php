<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Contracts\OperatorRequestsInterface;
use App\Domains\Conversation\DTO\OperatorRequest;
use App\Domains\Conversation\DTO\OperatorRequestDraft;
use App\Domains\Conversation\DTO\OperatorRequestPlacement;
use App\Domains\Conversation\Enums\OperatorRequestPlacementOutcome;
use App\Domains\Conversation\Enums\OperatorRequestState;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Contracts\OperatorRequestTimeoutSchedulerInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Handlers\RequestOperatorNodeHandler;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Flow\Support\OperatorRequestCancelButton;
use DateTimeInterface;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Messaging\ChannelCapabilities;
use Fapost\Foundation\Messaging\ChannelCapability;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * Covers {@see RequestOperatorNodeHandler}: the first run that places a request and tells the contact, the re-runs that
 * must place nothing twice, and the exits chosen from the request's own state. The Conversation side
 * ({@see \App\Domains\Conversation\Services\OperatorRequests}) is covered by its own test and mocked here.
 */
final class RequestOperatorNodeHandlerTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string NODE_ID = 'node-op';

    private const string REQUEST_ID = '11111111-1111-4111-8111-111111111111';

    private const string MARKER = SystemStateKeys::REQUEST_OPERATOR_PREFIX . '.' . self::NODE_ID;

    private MockInterface&OperatorRequestsInterface $requests;

    private MockInterface&MessageSenderInterface $sender;

    private MockInterface&InlineKeyboardEditorInterface $keyboards;

    private MockInterface&OperatorRequestTimeoutSchedulerInterface $timeouts;

    private Assistant $assistant;

    private Contact $contact;

    private string $sessionId;

    private ChannelCapabilities $capabilities;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requests  = Mockery::mock(OperatorRequestsInterface::class);
        $this->sender    = Mockery::mock(MessageSenderInterface::class);
        $this->keyboards = Mockery::mock(InlineKeyboardEditorInterface::class);
        $this->timeouts  = Mockery::mock(OperatorRequestTimeoutSchedulerInterface::class);

        $this->capabilities = new ChannelCapabilities([ChannelCapability::CallbackButtons]);
        $this->assistant    = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $this->contact      = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $this->sessionId    = $this->createSession();
    }

    /**
     * @return iterable<string, array{OperatorRequestPlacementOutcome}>
     */
    public static function refusals(): iterable
    {
        yield 'conversation logging is off' => [OperatorRequestPlacementOutcome::LoggingDisabled];
        yield 'the contact has no active channel' => [OperatorRequestPlacementOutcome::NoChannel];
    }

    /**
     * @return iterable<string, array{OperatorRequestState}>
     */
    public static function waitingStates(): iterable
    {
        yield 'pending' => [OperatorRequestState::Pending];
        yield 'accepted' => [OperatorRequestState::Accepted];
    }

    /**
     * @return iterable<string, array{OperatorRequestState, string}>
     */
    public static function exits(): iterable
    {
        yield 'returned' => [OperatorRequestState::Returned, 'returned'];
        yield 'cancelled' => [OperatorRequestState::Cancelled, 'cancelled'];
        yield 'timeout' => [OperatorRequestState::Timeout, 'timeout'];
    }

    /**
     * @return iterable<string, array{OperatorRequestState}>
     */
    public static function endedStates(): iterable
    {
        yield 'returned' => [OperatorRequestState::Returned];
        yield 'cancelled' => [OperatorRequestState::Cancelled];
        yield 'timeout' => [OperatorRequestState::Timeout];
    }

    public function test_the_first_run_places_the_request_tells_the_contact_with_a_cancel_button_and_schedules_the_timeout(): void
    {
        $draft = null;
        $this->requests->shouldReceive('place')->once()->andReturnUsing(function (OperatorRequestDraft $given) use (&$draft): OperatorRequestPlacement {
            $draft = $given;

            return $this->placement(OperatorRequestPlacementOutcome::Placed);
        });

        $payload = null;
        $this->sender->shouldReceive('send')->once()->andReturnUsing(function (string $tenantId, string $contactId, string $sessionId, array $given) use (&$payload): string {
            $payload = ['tenant' => $tenantId, 'contact' => $contactId, 'session' => $sessionId, 'payload' => $given];

            return 'ext-msg-1';
        });
        $this->requests->shouldReceive('attachMessage')->once()->with(self::REQUEST_ID, 'ext-msg-1', $this->sessionId)->andReturnTrue();
        $this->keyboards->shouldNotReceive('removeKeyboard');

        $expiresAt = null;
        $this->timeouts->shouldReceive('schedule')->once()->andReturnUsing(function (string $tenantId, string $requestId, DateTimeInterface $given) use (&$expiresAt): void {
            $this->assertSame(self::TENANT_ID, $tenantId);
            $this->assertSame(self::REQUEST_ID, $requestId);
            $expiresAt = $given;
        });

        $result = $this->execute(['wait_seconds' => 120, 'notify_by_email' => true, 'operator_text' => 'Call them back', 'target' => 'role', 'role' => 'admin']);

        $this->assertSame(NodeExecutionStatus::Waiting, $result->status);
        $this->assertSame([self::MARKER => self::REQUEST_ID], $result->stateChanges);
        $this->assertSame(self::REQUEST_ID, $result->metadata['request_id']);

        $this->assertNotNull($draft);
        $this->assertSame(self::TENANT_ID, $draft->tenantId);
        $this->assertSame((string) $this->assistant->getKey(), $draft->assistantId);
        $this->assertSame((string) $this->contact->getKey(), $draft->contactId);
        $this->assertSame($this->sessionId, $draft->sessionId);
        $this->assertSame(self::NODE_ID, $draft->nodeId);
        $this->assertSame(['target' => 'role', 'role' => 'admin', 'user_ids' => []], $draft->recipients);
        $this->assertTrue($draft->byEmail);
        $this->assertSame('Call them back', $draft->operatorNote);
        $this->assertSame('An operator connected', $draft->connectedText);
        $this->assertSame('The operator left', $draft->disconnectedText);

        $this->assertNotNull($payload);
        $this->assertSame($this->sessionId, $payload['session']);
        $this->assertSame('text_with_keyboard', $payload['payload']['content_type']);
        $this->assertSame('We asked an operator to join', $payload['payload']['text']);
        $this->assertSame('inline', $payload['payload']['keyboard_mode']);
        $this->assertSame([['id' => OperatorRequestCancelButton::ID, 'label' => 'Cancel request', 'row' => 0, 'order' => 0]], $payload['payload']['buttons']);
        $this->assertSame(self::REQUEST_ID . ':requested', $payload['payload']['idempotency_key']);
        $this->assertSame(self::NODE_ID, $payload['payload']['node_id']);

        $this->assertInstanceOf(DateTimeInterface::class, $expiresAt);
        $this->assertEqualsWithDelta(Carbon::now()->addSeconds(120)->getTimestamp(), $expiresAt->getTimestamp(), 5);
    }

    public function test_a_wait_of_zero_schedules_no_timeout(): void
    {
        $this->requests->shouldReceive('place')->once()->andReturn($this->placement(OperatorRequestPlacementOutcome::Placed));
        $this->sender->shouldReceive('send')->once()->andReturn('ext-msg-1');
        $this->requests->shouldReceive('attachMessage')->once()->andReturnTrue();
        $this->timeouts->shouldNotReceive('schedule');

        $result = $this->execute(['wait_seconds' => 0]);

        $this->assertSame(NodeExecutionStatus::Waiting, $result->status);
    }

    public function test_a_channel_without_callback_buttons_gets_the_text_alone(): void
    {
        $this->capabilities = new ChannelCapabilities([]);
        $this->requests->shouldReceive('place')->once()->andReturn($this->placement(OperatorRequestPlacementOutcome::Placed));
        $this->requests->shouldNotReceive('attachMessage');
        $this->keyboards->shouldNotReceive('removeKeyboard');
        $this->timeouts->shouldReceive('schedule')->once();

        $payload = null;
        $this->sender->shouldReceive('send')->once()->andReturnUsing(function (string $tenantId, string $contactId, string $sessionId, array $given) use (&$payload): string {
            $payload = $given;

            return 'ext-msg-1';
        });

        $this->execute();

        $this->assertSame('text', $payload['content_type']);
        $this->assertSame([], $payload['buttons']);
        $this->assertNull($payload['keyboard_mode']);
    }

    public function test_the_author_can_switch_the_cancel_button_off(): void
    {
        $this->requests->shouldReceive('place')->once()->andReturn($this->placement(OperatorRequestPlacementOutcome::Placed));
        $this->requests->shouldNotReceive('attachMessage');
        $this->timeouts->shouldReceive('schedule')->once();

        $payload = null;
        $this->sender->shouldReceive('send')->once()->andReturnUsing(function (string $tenantId, string $contactId, string $sessionId, array $given) use (&$payload): string {
            $payload = $given;

            return 'ext-msg-1';
        });

        $this->execute(['cancel_button' => false]);

        $this->assertSame('text', $payload['content_type']);
        $this->assertSame([], $payload['buttons']);
    }

    public function test_an_empty_request_sent_text_sends_nothing_but_still_waits(): void
    {
        $this->requests->shouldReceive('place')->once()->andReturn($this->placement(OperatorRequestPlacementOutcome::Placed));
        $this->sender->shouldNotReceive('send');
        $this->timeouts->shouldReceive('schedule')->once();

        $result = $this->execute(['requested_text' => '   ']);

        $this->assertSame(NodeExecutionStatus::Waiting, $result->status);
        $this->assertSame([self::MARKER => self::REQUEST_ID], $result->stateChanges);
    }

    public function test_a_failing_send_leaves_the_request_waiting(): void
    {
        $this->requests->shouldReceive('place')->once()->andReturn($this->placement(OperatorRequestPlacementOutcome::Placed));
        $this->sender->shouldReceive('send')->once()->andThrow(new RuntimeException('channel down'));
        $this->requests->shouldNotReceive('attachMessage');
        $this->timeouts->shouldReceive('schedule')->once();

        $result = $this->execute();

        $this->assertSame(NodeExecutionStatus::Waiting, $result->status);
        $this->assertSame([self::MARKER => self::REQUEST_ID], $result->stateChanges);
    }

    public function test_the_cancel_button_comes_off_when_the_request_stopped_waiting_while_the_message_was_on_its_way(): void
    {
        $this->requests->shouldReceive('place')->once()->andReturn($this->placement(OperatorRequestPlacementOutcome::Placed));
        $this->sender->shouldReceive('send')->once()->andReturn('ext-msg-9');
        $this->requests->shouldReceive('attachMessage')->once()->with(self::REQUEST_ID, 'ext-msg-9', $this->sessionId)->andReturnFalse();
        $this->keyboards->shouldReceive('removeKeyboard')->once()->with(self::TENANT_ID, (string) $this->contact->getKey(), $this->sessionId, 'ext-msg-9');
        $this->timeouts->shouldReceive('schedule')->once();

        $this->execute();
    }

    #[DataProvider('refusals')]
    public function test_a_request_that_could_not_be_placed_leaves_by_timeout_at_once(OperatorRequestPlacementOutcome $outcome): void
    {
        $this->requests->shouldReceive('place')->once()->andReturn(OperatorRequestPlacement::refused($outcome));
        $this->sender->shouldNotReceive('send');
        $this->timeouts->shouldNotReceive('schedule');

        $result = $this->execute();

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('timeout', $result->sourceHandle);
        $this->assertSame([], $result->stateChanges);
        $this->assertFalse($result->metadata['placed']);
        $this->assertSame($outcome->value, $result->metadata['reason']);
    }

    public function test_binding_to_a_request_or_an_operator_that_exists_waits_silently(): void
    {
        $this->requests->shouldReceive('place')->once()->andReturn($this->placement(OperatorRequestPlacementOutcome::Bound, OperatorRequestState::Accepted));
        $this->sender->shouldNotReceive('send');
        $this->timeouts->shouldNotReceive('schedule');

        $result = $this->execute();

        $this->assertSame(NodeExecutionStatus::Waiting, $result->status);
        $this->assertSame([self::MARKER => self::REQUEST_ID], $result->stateChanges);
        $this->assertSame('bound', $result->metadata['placement']);
    }

    #[DataProvider('waitingStates')]
    public function test_a_re_run_while_its_request_waits_places_nothing_new(OperatorRequestState $state): void
    {
        $this->requests->shouldReceive('find')->once()->with(self::REQUEST_ID)->andReturn($this->request($state));
        $this->requests->shouldNotReceive('place');
        $this->sender->shouldNotReceive('send');
        $this->timeouts->shouldNotReceive('schedule');

        $result = $this->execute(state: [self::MARKER => self::REQUEST_ID], nested: true);

        $this->assertSame(NodeExecutionStatus::Waiting, $result->status);
        $this->assertSame([], $result->stateChanges);
    }

    #[DataProvider('exits')]
    public function test_woken_after_the_request_ended_the_node_leaves_by_its_outcome(OperatorRequestState $state, string $handle): void
    {
        $this->requests->shouldReceive('find')->once()->with(self::REQUEST_ID)->andReturn($this->request($state));
        $this->requests->shouldNotReceive('place');

        $result = $this->execute(state: [self::MARKER => self::REQUEST_ID], nested: true, incoming: $this->incoming());

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame($handle, $result->sourceHandle);
        $this->assertSame(self::REQUEST_ID, $result->metadata['request_id']);
    }

    #[DataProvider('waitingStates')]
    public function test_woken_by_a_message_while_the_request_still_waits_the_node_keeps_waiting(OperatorRequestState $state): void
    {
        $this->requests->shouldReceive('find')->once()->andReturn($this->request($state));
        $this->requests->shouldNotReceive('place');

        $result = $this->execute(state: [self::MARKER => self::REQUEST_ID], nested: true, incoming: $this->incoming());

        $this->assertSame(NodeExecutionStatus::Waiting, $result->status);
    }

    public function test_woken_when_the_request_is_gone_the_node_leaves_by_cancelled_instead_of_hanging(): void
    {
        $this->requests->shouldReceive('find')->once()->andReturnNull();
        $this->requests->shouldNotReceive('place');

        $result = $this->execute(state: [self::MARKER => self::REQUEST_ID], nested: true, incoming: $this->incoming());

        $this->assertSame(NodeExecutionStatus::Executed, $result->status);
        $this->assertSame('cancelled', $result->sourceHandle);
    }

    #[DataProvider('endedStates')]
    public function test_a_new_pass_of_the_node_asks_again_once_its_old_request_ended(OperatorRequestState $state): void
    {
        $newId = '22222222-2222-4222-8222-222222222222';

        $this->requests->shouldReceive('find')->once()->with(self::REQUEST_ID)->andReturn($this->request($state));
        $this->requests->shouldReceive('place')->once()->andReturn($this->placement(OperatorRequestPlacementOutcome::Placed, id: $newId));
        $this->sender->shouldReceive('send')->once()->andReturn('ext-msg-2');
        $this->requests->shouldReceive('attachMessage')->once()->with($newId, 'ext-msg-2', $this->sessionId)->andReturnTrue();
        $this->timeouts->shouldReceive('schedule')->once();

        $result = $this->execute(state: [self::MARKER => self::REQUEST_ID], nested: true);

        $this->assertSame(NodeExecutionStatus::Waiting, $result->status);
        $this->assertSame([self::MARKER => $newId], $result->stateChanges);
    }

    public function test_a_marker_whose_request_belongs_to_another_session_is_not_taken_for_this_ones(): void
    {
        $this->requests->shouldReceive('find')->once()->andReturn($this->request(OperatorRequestState::Pending, sessionId: 'someone-else'));
        $this->requests->shouldReceive('place')->once()->andReturn($this->placement(OperatorRequestPlacementOutcome::Bound));
        $this->sender->shouldNotReceive('send');

        $result = $this->execute(state: [self::MARKER => self::REQUEST_ID], nested: true);

        $this->assertSame(NodeExecutionStatus::Waiting, $result->status);
        $this->assertSame('bound', $result->metadata['placement']);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     * @param  bool                  $nested  whether `$state` is already in the engine's nested shape
     */
    private function execute(array $config = [], array $state = [], bool $nested = false, ?IncomingMessage $incoming = null): NodeExecutionResult
    {
        $handler = $this->app->make(RequestOperatorNodeHandler::class, [
            'requests'       => $this->requests,
            'sender'         => $this->sender,
            'keyboardEditor' => $this->keyboards,
            'timeouts'       => $this->timeouts,
            'channels'       => $this->channels(),
        ]);

        $node = [
            'id'     => self::NODE_ID,
            'type'   => 'request_operator',
            'config' => $config + [
                'requested_text'    => 'We asked an operator to join',
                'cancel_button'     => true,
                'cancel_label'      => 'Cancel request',
                'connected_text'    => 'An operator connected',
                'disconnected_text' => 'The operator left',
                'wait_seconds'      => 300,
            ],
        ];

        return $handler->execute($node, $nested ? $this->expand($state) : $state, new NodeExecutionContext(
            tenantId: self::TENANT_ID,
            contactId: (string) $this->contact->getKey(),
            sessionId: $this->sessionId,
            nodeId: self::NODE_ID,
            idempotencyKey: 'idem-1',
            platform: 'telegram',
            incoming: $incoming,
        ));
    }

    /**
     * `system.request_operator.node-op` => id, as the nested array the engine hands a handler.
     *
     * @param  array<string, mixed>  $flat
     *
     * @return array<string, mixed>
     */
    private function expand(array $flat): array
    {
        $nested = [];

        foreach ($flat as $path => $value) {
            data_set($nested, $path, $value);
        }

        return $nested;
    }

    private function channels(): ChannelRegistryInterface
    {
        $registry = Mockery::mock(ChannelRegistryInterface::class);
        $registry->shouldReceive('capabilities')->with('telegram')->andReturn($this->capabilities);

        return $registry;
    }

    private function incoming(): IncomingMessage
    {
        return new IncomingMessage(
            updateId: 'operator_request:' . self::REQUEST_ID . ':returned',
            externalUserId: '',
            externalChatId: '',
            text: null,
            type: IncomingMessageType::Unknown,
            platform: 'telegram',
        );
    }

    private function placement(OperatorRequestPlacementOutcome $outcome, OperatorRequestState $state = OperatorRequestState::Pending, string $id = self::REQUEST_ID): OperatorRequestPlacement
    {
        return new OperatorRequestPlacement($outcome, $this->request($state, $id), 'telegram');
    }

    private function request(OperatorRequestState $state, string $id = self::REQUEST_ID, ?string $sessionId = null): OperatorRequest
    {
        return new OperatorRequest(
            id: $id,
            conversationId: (string) Str::uuid(),
            tenantId: self::TENANT_ID,
            assistantId: (string) $this->assistant->getKey(),
            contactId: (string) $this->contact->getKey(),
            platform: 'telegram',
            state: $state,
            sessionId: $sessionId ?? $this->sessionId,
            nodeId: self::NODE_ID,
        );
    }

    private function createSession(): string
    {
        $flow = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Operator Flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $session = FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $this->assistant->getKey(),
            'contact_id'         => $this->contact->getKey(),
            'flow_definition_id' => $flow->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => self::NODE_ID,
            'state'              => [],
            'status'             => FlowSessionStatus::Active,
            'version'            => 0,
        ]);

        return (string) $session->getKey();
    }
}
