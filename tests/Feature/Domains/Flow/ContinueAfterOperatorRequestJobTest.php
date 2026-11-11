<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Enums\OperatorRequestState;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Jobs\Flow\ContinueAfterOperatorRequestJob;
use App\Jobs\Flow\ExpireOperatorRequestJob;
use Closure;
use Fapost\Foundation\DTO\IncomingMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * What the contact's side does once an operator request changed state ({@see ContinueAfterOperatorRequestJob}), and
 * the job that ends an unanswered wait ({@see ExpireOperatorRequestJob}). The flow engine, the session lock and the
 * channel are replaced; the request is a real one on a real thread.
 */
final class ContinueAfterOperatorRequestJobTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string NODE_ID = 'node-op';

    private MockInterface&FlowEngineInterface $engine;

    private MockInterface&MessageSenderInterface $sender;

    private MockInterface&InlineKeyboardEditorInterface $keyboards;

    private Assistant $assistant;

    private Contact $contact;

    private FlowDefinition $flow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine    = Mockery::mock(FlowEngineInterface::class);
        $this->sender    = Mockery::mock(MessageSenderInterface::class);
        $this->keyboards = Mockery::mock(InlineKeyboardEditorInterface::class);

        $guard = Mockery::mock(FlowExecutionGuardInterface::class);
        $guard->shouldReceive('run')->andReturnUsing(static fn (string $tenantId, string $contactId, string $assistantId, Closure $callback): mixed => $callback());

        $this->app->instance(FlowEngineInterface::class, $this->engine);
        $this->app->instance(MessageSenderInterface::class, $this->sender);
        $this->app->instance(InlineKeyboardEditorInterface::class, $this->keyboards);
        $this->app->instance(FlowExecutionGuardInterface::class, $guard);

        $this->assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $this->contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $this->flow      = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Operator Flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);
    }

    /**
     * @return iterable<string, array{OperatorRequestState}>
     */
    public static function endsWithoutAnOperator(): iterable
    {
        yield 'cancelled' => [OperatorRequestState::Cancelled];
        yield 'timeout' => [OperatorRequestState::Timeout];
    }

    /**
     * @return iterable<string, array{Closure(FlowSession): void}>
     */
    public static function sessionsNotWaitingThere(): iterable
    {
        yield 'it ended meanwhile' => [static fn (FlowSession $session) => $session->forceFill(['status' => FlowSessionStatus::TerminatedByUser, 'current_node_id' => null])->save()];
        yield 'it moved to another node' => [static fn (FlowSession $session) => $session->forceFill(['current_node_id' => 'elsewhere'])->save()];
        yield 'it waits for another request' => [static fn (FlowSession $session) => $session->forceFill(['state' => ['system' => ['request_operator' => [self::NODE_ID => 'another-request']]]])->save()];
        yield 'it is active again' => [static fn (FlowSession $session) => $session->forceFill(['status' => FlowSessionStatus::Active])->save()];
    }

    public function test_a_returned_request_tells_the_contact_the_operator_left_and_moves_the_waiting_session_on(): void
    {
        $session = $this->waitingSession();
        $id      = $this->recordRequest(OperatorRequestState::Returned, $session, disconnected: 'The operator left', messageId: 'ext-1');

        $this->sender->shouldReceive('send')->once()->withArgs(fn (string $tenantId, string $contactId, string $sessionId, array $payload): bool => self::TENANT_ID === $tenantId
            && $contactId === (string) $this->contact->getKey()
            && $sessionId === (string) $session->getKey()
            && 'text' === $payload['content_type']
            && 'The operator left' === $payload['text']
            && self::NODE_ID === $payload['node_id']
            && "{$id}:returned" === $payload['idempotency_key'])->andReturn('ext-2');
        // The operator took the button's request over long before: nothing is left to remove.
        $this->keyboards->shouldNotReceive('removeKeyboard');
        $this->engine->shouldReceive('resume')->once()->withArgs(fn (FlowSession $given, IncomingMessage $message): bool => $given->getKey() === $session->getKey()
            && "operator_request:{$id}:returned" === $message->updateId
            && $message->payload === ['operator_request' => $id, 'state' => 'returned']
            && 'telegram' === $message->platform)->andReturn($session);

        $this->runJob($id, OperatorRequestState::Returned);
    }

    #[DataProvider('endsWithoutAnOperator')]
    public function test_a_cancelled_or_timed_out_request_removes_the_button_says_nothing_and_moves_the_session_on(OperatorRequestState $state): void
    {
        $session = $this->waitingSession();
        $id      = $this->recordRequest($state, $session, connected: 'Connected', disconnected: 'Left', messageId: 'ext-1');

        $this->sender->shouldNotReceive('send');
        $this->keyboards->shouldReceive('removeKeyboard')->once()->with(self::TENANT_ID, (string) $this->contact->getKey(), (string) $session->getKey(), 'ext-1');
        $this->engine->shouldReceive('resume')->once()->andReturn($session);

        $this->runJob($id, $state);
    }

    public function test_an_accepted_request_tells_the_contact_an_operator_connected_and_leaves_the_session_waiting(): void
    {
        $session = $this->waitingSession();
        $id      = $this->recordRequest(OperatorRequestState::Accepted, $session, connected: 'An operator connected', disconnected: 'Left', messageId: 'ext-1');

        $this->sender->shouldReceive('send')->once()->withArgs(fn (string $tenantId, string $contactId, string $sessionId, array $payload): bool => 'An operator connected' === $payload['text']
            && "{$id}:accepted" === $payload['idempotency_key'])->andReturn('ext-2');
        $this->keyboards->shouldReceive('removeKeyboard')->once()->with(self::TENANT_ID, (string) $this->contact->getKey(), (string) $session->getKey(), 'ext-1');
        $this->engine->shouldNotReceive('resume');

        $this->runJob($id, OperatorRequestState::Accepted);
    }

    public function test_a_request_without_the_cancel_button_message_removes_nothing(): void
    {
        $session = $this->waitingSession();
        $id      = $this->recordRequest(OperatorRequestState::Accepted, $session, connected: 'Connected');

        $this->sender->shouldReceive('send')->once()->andReturn('ext-2');
        $this->keyboards->shouldNotReceive('removeKeyboard');

        $this->runJob($id, OperatorRequestState::Accepted);
    }

    public function test_a_state_without_a_text_for_the_contact_sends_nothing(): void
    {
        $session = $this->waitingSession();
        // No connected text (the author left it empty) and a blank one for "left".
        $accepted = $this->recordRequest(OperatorRequestState::Accepted, $session);
        $this->sender->shouldNotReceive('send');

        $this->runJob($accepted, OperatorRequestState::Accepted);

        $returnedSession = $this->waitingSession(contact: Contact::factory()->forTenant(self::TENANT_ID)->create());
        $returned        = $this->recordRequest(OperatorRequestState::Returned, $returnedSession, disconnected: '   ');
        $this->engine->shouldReceive('resume')->once()->andReturn($returnedSession);

        $this->runJob($returned, OperatorRequestState::Returned);
    }

    /**
     * @param  Closure(FlowSession): void  $diverge
     */
    #[DataProvider('sessionsNotWaitingThere')]
    public function test_a_session_that_does_not_wait_in_that_node_for_that_request_is_not_continued(Closure $diverge): void
    {
        $session = $this->waitingSession();
        $id      = $this->recordRequest(OperatorRequestState::Returned, $session, disconnected: 'The operator left');
        $diverge($session);

        // The text and the button still follow from the request alone.
        $this->sender->shouldReceive('send')->once()->andReturn('ext-2');
        $this->engine->shouldNotReceive('resume');

        $this->runJob($id, OperatorRequestState::Returned);
    }

    public function test_a_session_that_no_longer_exists_is_not_continued(): void
    {
        $session = $this->waitingSession();
        $id      = $this->recordRequest(OperatorRequestState::Returned, $session, disconnected: 'The operator left');
        FlowSession::query()->whereKey($session->getKey())->delete();

        $this->sender->shouldReceive('send')->once()->andReturn('ext-2');
        $this->engine->shouldNotReceive('resume');

        $this->runJob($id, OperatorRequestState::Returned);
    }

    public function test_a_failing_send_does_not_stop_the_session_from_moving_on(): void
    {
        $session = $this->waitingSession();
        $id      = $this->recordRequest(OperatorRequestState::Returned, $session, disconnected: 'The operator left');

        $this->sender->shouldReceive('send')->once()->andThrow(new RuntimeException('channel down'));
        $this->engine->shouldReceive('resume')->once()->andReturn($session);

        $this->runJob($id, OperatorRequestState::Returned);
    }

    public function test_an_unknown_request_does_nothing(): void
    {
        $this->sender->shouldNotReceive('send');
        $this->keyboards->shouldNotReceive('removeKeyboard');
        $this->engine->shouldNotReceive('resume');

        $this->runJob((string) Str::uuid(), OperatorRequestState::Returned);
    }

    public function test_the_expiry_job_times_out_a_pending_request_only(): void
    {
        Event::fake();
        $session = $this->waitingSession();
        $id      = $this->recordRequest(OperatorRequestState::Pending, $session);

        $this->app->call([new ExpireOperatorRequestJob(self::TENANT_ID, $id), 'handle']);

        $thread = Conversation::query()->where('operator_request_id', $id)->sole();
        $this->assertSame(OperatorRequestState::Timeout->value, $thread->operator_request['state']);
        $this->assertSame(ConversationOwner::Bot, $thread->owner_type);

        // Accepted by an operator first: the late job changes nothing.
        $other      = $this->waitingSession(contact: Contact::factory()->forTenant(self::TENANT_ID)->create());
        $acceptedId = $this->recordRequest(OperatorRequestState::Accepted, $other, owner: ConversationOwner::Staff);

        $this->app->call([new ExpireOperatorRequestJob(self::TENANT_ID, $acceptedId), 'handle']);

        $accepted = Conversation::query()->where('operator_request_id', $acceptedId)->sole();
        $this->assertSame(OperatorRequestState::Accepted->value, $accepted->operator_request['state']);
        $this->assertSame(ConversationOwner::Staff, $accepted->owner_type);
    }

    private function runJob(string $requestId, OperatorRequestState $state): void
    {
        $this->app->call([new ContinueAfterOperatorRequestJob(self::TENANT_ID, $requestId, $state), 'handle']);
    }

    /**
     * A session waiting in the request node for the request the test records next.
     */
    private function waitingSession(?Contact $contact = null): FlowSession
    {
        $contact ??= $this->contact;

        return FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $this->assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $this->flow->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => self::NODE_ID,
            'state'              => [],
            'status'             => FlowSessionStatus::WaitingInput,
            'version'            => 0,
        ]);
    }

    /**
     * Records the request on a thread of the session's contact and points the session's marker at it.
     */
    private function recordRequest(
        OperatorRequestState $state,
        FlowSession $session,
        ?string $connected = null,
        ?string $disconnected = null,
        ?string $messageId = null,
        ConversationOwner $owner = ConversationOwner::Bot,
    ): string {
        $id      = (string) Str::uuid();
        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $this->assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
        ]));

        Conversation::query()->create([
            'tenant_id'           => self::TENANT_ID,
            'assistant_id'        => $this->assistant->getKey(),
            'contact_id'          => $session->contact_id,
            'channel_id'          => $channel->getKey(),
            'platform'            => 'telegram',
            'owner_type'          => OperatorRequestState::Pending === $state ? ConversationOwner::Queued : $owner,
            'queued_at'           => OperatorRequestState::Pending === $state ? now() : null,
            'operator_request_id' => $id,
            'operator_request'    => [
                'state'              => $state->value,
                'session_id'         => (string) $session->getKey(),
                'node_id'            => self::NODE_ID,
                'message_id'         => $messageId,
                'message_session_id' => null === $messageId ? null : (string) $session->getKey(),
                'connected_text'     => $connected,
                'disconnected_text'  => $disconnected,
                'accepted_by'        => null,
            ],
        ]);

        $session->forceFill(['state' => ['system' => ['request_operator' => [self::NODE_ID => $id]]]])->save();

        return $id;
    }
}
