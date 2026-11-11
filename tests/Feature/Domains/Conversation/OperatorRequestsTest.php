<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Conversation;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Contracts\ConversationOwnershipInterface;
use App\Domains\Conversation\Contracts\OperatorRequestsInterface;
use App\Domains\Conversation\DTO\OperatorRequest;
use App\Domains\Conversation\DTO\OperatorRequestDraft;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Enums\OperatorRequestPlacementOutcome;
use App\Domains\Conversation\Enums\OperatorRequestState;
use App\Domains\Conversation\Events\OperatorRequestStateChanged;
use App\Domains\Conversation\Jobs\NotifyOperatorRequestJob;
use App\Domains\Conversation\Models\Conversation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * Covers {@see \App\Domains\Conversation\Services\OperatorRequests} and the request side of
 * {@see \App\Domains\Conversation\Services\ConversationOwnership}: placing a request on a thread, binding a second
 * session, cancelling or expiring it, accepting it by taking the thread over and returning it by finishing.
 */
final class OperatorRequestsTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string STAFF_ID = '00000000-0000-0000-0000-0000000000aa';

    private const string OTHER_STAFF_ID = '00000000-0000-0000-0000-0000000000ab';

    private const string SESSION_A = '00000000-0000-0000-0000-00000000a001';

    private const string SESSION_B = '00000000-0000-0000-0000-00000000b002';

    private const string NODE_A = 'node-a';

    private const string NODE_B = 'node-b';

    private Assistant $assistant;

    private Contact $contact;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        config(['conversation.enabled' => true]);

        $this->assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $this->contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $this->channel   = $this->channelFor($this->contact, true);
    }

    public function test_placing_on_a_contact_without_a_thread_creates_a_queued_thread_with_a_pending_request(): void
    {
        Bus::fake([NotifyOperatorRequestJob::class]);
        $this->assertSame(0, Conversation::query()->count());

        $placement = $this->requests()->place($this->draft());

        $this->assertSame(OperatorRequestPlacementOutcome::Placed, $placement->outcome);
        $this->assertSame('telegram', $placement->channelType);
        $this->assertNotNull($placement->request);

        $thread = Conversation::query()->sole();
        $this->assertSame(ConversationOwner::Queued, $thread->owner_type);
        $this->assertNull($thread->owner_staff_user_id);
        $this->assertInstanceOf(Carbon::class, $thread->queued_at);
        $this->assertSame($placement->request->id, $thread->operator_request_id);
        $this->assertSame((string) $this->channel->getKey(), (string) $thread->channel_id);
        $this->assertSame('telegram', $thread->platform);

        $record = OperatorRequest::fromConversation($thread);
        $this->assertNotNull($record);
        $this->assertSame(OperatorRequestState::Pending, $record->state);
        $this->assertSame(self::SESSION_A, $record->sessionId);
        $this->assertSame(self::NODE_A, $record->nodeId);
        $this->assertSame('An operator connected', $record->connectedText);
        $this->assertSame('The operator left', $record->disconnectedText);
        $this->assertNull($record->acceptedBy);
        $this->assertTrue(Str::isUuid($record->id));
    }

    public function test_placing_dispatches_the_notification_job_for_that_request(): void
    {
        Bus::fake([NotifyOperatorRequestJob::class]);

        $placement = $this->requests()->place($this->draft(byEmail: true, note: 'Call the customer'));

        Bus::assertDispatchedTimes(NotifyOperatorRequestJob::class, 1);
        Bus::assertDispatched(NotifyOperatorRequestJob::class, fn (NotifyOperatorRequestJob $job): bool => $job->requestId === $placement->request?->id
            && self::TENANT_ID === $job->tenantId
            && true === $job->byEmail
            && 'Call the customer' === $job->operatorNote
            && 'assistant' === $job->recipients['target']);
    }

    public function test_placing_takes_the_bot_thread_the_transcript_already_created(): void
    {
        Bus::fake([NotifyOperatorRequestJob::class]);
        $existing = $this->thread(['owner_type' => ConversationOwner::Bot]);

        $placement = $this->requests()->place($this->draft());

        $this->assertSame(OperatorRequestPlacementOutcome::Placed, $placement->outcome);
        $this->assertSame(1, Conversation::query()->count());
        $this->assertSame($placement->request?->conversationId, (string) $existing->getKey());
        $this->assertSame(ConversationOwner::Queued, $existing->fresh()->owner_type);
    }

    public function test_nothing_is_placed_when_the_tenant_keeps_no_conversation_log(): void
    {
        Bus::fake([NotifyOperatorRequestJob::class]);
        config(['conversation.enabled' => false]);
        $this->app->forgetScopedInstances();

        $placement = $this->requests()->place($this->draft());

        $this->assertSame(OperatorRequestPlacementOutcome::LoggingDisabled, $placement->outcome);
        $this->assertNull($placement->request);
        $this->assertSame(0, Conversation::query()->count());
        Bus::assertNotDispatched(NotifyOperatorRequestJob::class);
    }

    public function test_nothing_is_placed_when_the_contact_has_no_active_channel_at_the_assistant(): void
    {
        Bus::fake([NotifyOperatorRequestJob::class]);

        // A contact without any channel, and one whose only channel is switched off.
        $stranger = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $inactive = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $this->channelFor($inactive, false);

        foreach ([$stranger, $inactive] as $contact) {
            $placement = $this->requests()->place($this->draft(contact: $contact));

            $this->assertSame(OperatorRequestPlacementOutcome::NoChannel, $placement->outcome);
            $this->assertNull($placement->request);
        }

        $this->assertSame(0, Conversation::query()->count());
        Bus::assertNotDispatched(NotifyOperatorRequestJob::class);
    }

    public function test_a_channel_of_another_assistant_does_not_count(): void
    {
        $otherAssistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact        = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $this->channelFor($contact, true, $otherAssistant);

        $placement = $this->requests()->place($this->draft(contact: $contact));

        $this->assertSame(OperatorRequestPlacementOutcome::NoChannel, $placement->outcome);
    }

    public function test_the_same_node_of_the_same_session_again_gets_the_same_request(): void
    {
        Bus::fake([NotifyOperatorRequestJob::class]);

        $first  = $this->requests()->place($this->draft());
        $second = $this->requests()->place($this->draft());

        $this->assertSame(OperatorRequestPlacementOutcome::Placed, $second->outcome);
        $this->assertSame($first->request?->id, $second->request?->id);
        $this->assertSame(1, Conversation::query()->count());
        $this->assertSame($first->request?->id, Conversation::query()->sole()->operator_request_id);
    }

    public function test_another_session_binds_to_the_waiting_request_with_its_own_texts(): void
    {
        Bus::fake([NotifyOperatorRequestJob::class]);
        $first = $this->requests()->place($this->draft());

        $second = $this->requests()->place($this->draft(sessionId: self::SESSION_B, nodeId: self::NODE_B, connected: 'Hello again', disconnected: 'Bye again'));

        $this->assertSame(OperatorRequestPlacementOutcome::Bound, $second->outcome);
        $this->assertSame($first->request?->id, $second->request?->id);

        $record = $this->requests()->find((string) $first->request?->id);
        $this->assertNotNull($record);
        $this->assertSame(OperatorRequestState::Pending, $record->state);
        $this->assertSame(self::SESSION_B, $record->sessionId);
        $this->assertSame(self::NODE_B, $record->nodeId);
        $this->assertSame('Hello again', $record->connectedText);
        $this->assertSame('Bye again', $record->disconnectedText);

        // The operators were told once, by the request that was placed.
        Bus::assertDispatchedTimes(NotifyOperatorRequestJob::class, 1);
    }

    public function test_a_thread_an_operator_holds_gets_an_accepted_request_and_keeps_its_owner(): void
    {
        Bus::fake([NotifyOperatorRequestJob::class]);
        $thread = $this->thread(['owner_type' => ConversationOwner::Staff, 'owner_staff_user_id' => self::STAFF_ID]);

        $placement = $this->requests()->place($this->draft());

        $this->assertSame(OperatorRequestPlacementOutcome::Bound, $placement->outcome);
        $this->assertSame(OperatorRequestState::Accepted, $placement->request?->state);
        $this->assertSame(self::STAFF_ID, $placement->request?->acceptedBy);

        $fresh = $thread->fresh();
        $this->assertSame(ConversationOwner::Staff, $fresh->owner_type);
        $this->assertSame(self::STAFF_ID, $fresh->owner_staff_user_id);
        $this->assertSame($placement->request?->id, $fresh->operator_request_id);
        Bus::assertNotDispatched(NotifyOperatorRequestJob::class);

        // The same node again finds that request instead of writing another.
        $again = $this->requests()->place($this->draft());
        $this->assertSame(OperatorRequestPlacementOutcome::Bound, $again->outcome);
        $this->assertSame($placement->request?->id, $again->request?->id);
    }

    public function test_finishing_a_thread_that_was_bound_while_held_returns_the_request(): void
    {
        $thread = $this->thread(['owner_type' => ConversationOwner::Staff, 'owner_staff_user_id' => self::STAFF_ID]);
        $id     = (string) $this->requests()->place($this->draft())->request?->id;
        $this->fakeStateEvents();

        $this->assertTrue($this->ownership()->finish((string) $thread->getKey(), self::STAFF_ID));

        $this->assertSame(OperatorRequestState::Returned, $this->requests()->find($id)?->state);
        $this->assertSame(ConversationOwner::Bot, $thread->fresh()->owner_type);
        Event::assertDispatched(OperatorRequestStateChanged::class, fn (OperatorRequestStateChanged $event): bool => $event->requestId === $id && OperatorRequestState::Returned === $event->state);
    }

    public function test_cancelling_a_pending_request_gives_the_thread_back_to_the_bot(): void
    {
        $id = $this->place();
        $this->fakeStateEvents();

        $this->assertTrue($this->requests()->cancel($id));

        $thread = Conversation::query()->sole();
        $this->assertSame(ConversationOwner::Bot, $thread->owner_type);
        $this->assertNull($thread->queued_at);
        $this->assertSame(OperatorRequestState::Cancelled, $this->requests()->find($id)?->state);
        Event::assertDispatchedTimes(OperatorRequestStateChanged::class, 1);
        Event::assertDispatched(OperatorRequestStateChanged::class, fn (OperatorRequestStateChanged $event): bool => $event->requestId === $id
            && self::TENANT_ID === $event->tenantId
            && OperatorRequestState::Cancelled === $event->state
            && null === $event->actorUserId);

        // Settled already: neither a second cancel nor a late expiry changes or announces anything.
        $this->assertFalse($this->requests()->cancel($id));
        $this->assertFalse($this->requests()->expire($id));
        Event::assertDispatchedTimes(OperatorRequestStateChanged::class, 1);
        $this->assertSame(OperatorRequestState::Cancelled, $this->requests()->find($id)?->state);
    }

    public function test_expiring_a_pending_request_marks_it_timed_out(): void
    {
        $id = $this->place();
        $this->fakeStateEvents();

        $this->assertTrue($this->requests()->expire($id));

        $this->assertSame(ConversationOwner::Bot, Conversation::query()->sole()->owner_type);
        $this->assertSame(OperatorRequestState::Timeout, $this->requests()->find($id)?->state);
        Event::assertDispatched(OperatorRequestStateChanged::class, fn (OperatorRequestStateChanged $event): bool => OperatorRequestState::Timeout === $event->state);
        $this->assertFalse($this->requests()->expire($id));
        $this->assertFalse($this->requests()->cancel($id));
    }

    public function test_expiring_after_an_operator_took_the_thread_changes_nothing(): void
    {
        $id     = $this->place();
        $thread = Conversation::query()->sole();
        $this->assertTrue($this->ownership()->takeOver((string) $thread->getKey(), self::STAFF_ID));
        $this->fakeStateEvents();

        $this->assertFalse($this->requests()->expire($id));
        $this->assertFalse($this->requests()->cancel($id));

        $fresh = $thread->fresh();
        $this->assertSame(ConversationOwner::Staff, $fresh->owner_type);
        $this->assertSame(self::STAFF_ID, $fresh->owner_staff_user_id);
        $this->assertSame(OperatorRequestState::Accepted, $this->requests()->find($id)?->state);
        Event::assertNotDispatched(OperatorRequestStateChanged::class);
    }

    public function test_an_unknown_or_malformed_request_id_is_refused_quietly(): void
    {
        $this->fakeStateEvents();

        foreach (['not-a-uuid', '', (string) Str::uuid()] as $id) {
            $this->assertNull($this->requests()->find($id));
            $this->assertFalse($this->requests()->cancel($id));
            $this->assertFalse($this->requests()->expire($id));
            $this->assertFalse($this->requests()->attachMessage($id, 'm1', self::SESSION_A));
        }

        Event::assertNotDispatched(OperatorRequestStateChanged::class);
    }

    public function test_taking_over_a_queued_thread_accepts_its_request(): void
    {
        $id     = $this->place();
        $thread = Conversation::query()->sole();
        $this->fakeStateEvents();

        $this->assertTrue($this->ownership()->takeOver((string) $thread->getKey(), self::STAFF_ID));

        $fresh = $thread->fresh();
        $this->assertSame(ConversationOwner::Staff, $fresh->owner_type);
        $this->assertSame(self::STAFF_ID, $fresh->owner_staff_user_id);
        $this->assertNull($fresh->queued_at);

        $request = $this->requests()->find($id);
        $this->assertSame(OperatorRequestState::Accepted, $request?->state);
        $this->assertSame(self::STAFF_ID, $request?->acceptedBy);
        Event::assertDispatched(OperatorRequestStateChanged::class, fn (OperatorRequestStateChanged $event): bool => $event->requestId === $id
            && OperatorRequestState::Accepted === $event->state
            && self::STAFF_ID === $event->actorUserId);
    }

    public function test_only_one_of_two_operators_accepts_the_request(): void
    {
        $this->place();
        $thread = Conversation::query()->sole();
        $this->fakeStateEvents();

        $this->assertTrue($this->ownership()->takeOver((string) $thread->getKey(), self::STAFF_ID));
        $this->assertFalse($this->ownership()->takeOver((string) $thread->getKey(), self::OTHER_STAFF_ID));

        Event::assertDispatchedTimes(OperatorRequestStateChanged::class, 1);
        $this->assertSame(self::STAFF_ID, $thread->fresh()->owner_staff_user_id);
    }

    public function test_finishing_returns_an_accepted_request_but_only_for_the_holder(): void
    {
        $id     = $this->place();
        $thread = Conversation::query()->sole();
        $this->ownership()->takeOver((string) $thread->getKey(), self::STAFF_ID);
        $this->fakeStateEvents();

        $this->assertFalse($this->ownership()->finish((string) $thread->getKey(), self::OTHER_STAFF_ID));
        $this->assertSame(OperatorRequestState::Accepted, $this->requests()->find($id)?->state);
        Event::assertNotDispatched(OperatorRequestStateChanged::class);

        $this->assertTrue($this->ownership()->finish((string) $thread->getKey(), self::STAFF_ID));

        $this->assertSame(ConversationOwner::Bot, $thread->fresh()->owner_type);
        $returned = $this->requests()->find($id);
        $this->assertSame(OperatorRequestState::Returned, $returned?->state);
        $this->assertSame(self::STAFF_ID, $returned?->acceptedBy);
        Event::assertDispatched(OperatorRequestStateChanged::class, fn (OperatorRequestStateChanged $event): bool => $event->requestId === $id
            && OperatorRequestState::Returned === $event->state
            && self::STAFF_ID === $event->actorUserId);
    }

    public function test_taking_over_after_the_request_timed_out_is_a_plain_takeover(): void
    {
        $id     = $this->place();
        $thread = Conversation::query()->sole();
        $this->requests()->expire($id);
        $this->fakeStateEvents();

        $this->assertTrue($this->ownership()->takeOver((string) $thread->getKey(), self::STAFF_ID));

        $this->assertSame(ConversationOwner::Staff, $thread->fresh()->owner_type);
        $this->assertSame(OperatorRequestState::Timeout, $this->requests()->find($id)?->state);
        Event::assertNotDispatched(OperatorRequestStateChanged::class);

        // Finishing that thread has no accepted request to return either.
        $this->assertTrue($this->ownership()->finish((string) $thread->getKey(), self::STAFF_ID));
        $this->assertSame(OperatorRequestState::Timeout, $this->requests()->find($id)?->state);
        Event::assertNotDispatched(OperatorRequestStateChanged::class);
    }

    public function test_ordinary_ownership_changes_without_a_request_announce_nothing(): void
    {
        $thread = $this->thread(['owner_type' => ConversationOwner::Bot]);
        $this->fakeStateEvents();

        $this->assertTrue($this->ownership()->takeOver((string) $thread->getKey(), self::STAFF_ID));
        $this->assertTrue($this->ownership()->finish((string) $thread->getKey(), self::STAFF_ID));

        Event::assertNotDispatched(OperatorRequestStateChanged::class);
    }

    public function test_the_cancel_button_message_is_recorded_only_while_the_request_is_pending(): void
    {
        $id = $this->place();

        $this->assertTrue($this->requests()->attachMessage($id, 'msg-1', self::SESSION_A));

        $request = $this->requests()->find($id);
        $this->assertSame('msg-1', $request?->messageId);
        $this->assertSame(self::SESSION_A, $request?->messageSessionId);
        $this->assertSame(OperatorRequestState::Pending, $request?->state);

        $this->ownership()->takeOver((string) Conversation::query()->sole()->getKey(), self::STAFF_ID);

        $this->assertFalse($this->requests()->attachMessage($id, 'msg-2', self::SESSION_A));
        $this->assertSame('msg-1', $this->requests()->find($id)?->messageId);
    }

    public function test_cancelling_for_a_session_matches_the_asking_session_and_the_one_that_sent_the_button(): void
    {
        // By the session that asked.
        $id = $this->place();
        $this->assertTrue($this->cancelForSession(self::SESSION_A));
        $this->assertSame(OperatorRequestState::Cancelled, $this->requests()->find($id)?->state);

        // By the session that sent the button, after another session took the request over.
        $id = $this->place();
        $this->requests()->attachMessage($id, 'msg-1', self::SESSION_A);
        $this->requests()->place($this->draft(sessionId: self::SESSION_B, nodeId: self::NODE_B));
        $this->assertSame(self::SESSION_B, $this->requests()->find($id)?->sessionId);

        $this->assertTrue($this->cancelForSession(self::SESSION_A));
        $this->assertSame(OperatorRequestState::Cancelled, $this->requests()->find($id)?->state);
        $this->assertSame(ConversationOwner::Bot, Conversation::query()->sole()->owner_type);
    }

    public function test_cancelling_for_a_session_ignores_other_sessions_other_contacts_and_settled_requests(): void
    {
        $id = $this->place();

        // Another session, another assistant, another contact: nothing matches.
        $this->assertFalse($this->cancelForSession(self::SESSION_B));
        $this->assertFalse($this->requests()->cancelForSession(self::TENANT_ID, (string) Assistant::factory()->create(['tenant_id' => self::TENANT_ID])->getKey(), (string) $this->contact->getKey(), self::SESSION_A));
        $this->assertFalse($this->requests()->cancelForSession(self::TENANT_ID, (string) $this->assistant->getKey(), (string) Contact::factory()->forTenant(self::TENANT_ID)->create()->getKey(), self::SESSION_A));
        $this->assertSame(OperatorRequestState::Pending, $this->requests()->find($id)?->state);
        $this->assertSame(ConversationOwner::Queued, Conversation::query()->sole()->owner_type);

        // Accepted: the operator has the thread now, and a command of the session leaves it to them.
        $this->ownership()->takeOver((string) Conversation::query()->sole()->getKey(), self::STAFF_ID);
        $this->assertFalse($this->cancelForSession(self::SESSION_A));
        $this->assertSame(OperatorRequestState::Accepted, $this->requests()->find($id)?->state);
        $this->assertSame(ConversationOwner::Staff, Conversation::query()->sole()->owner_type);
    }

    public function test_cancelling_for_a_session_does_not_touch_a_thread_without_a_request(): void
    {
        $this->thread(['owner_type' => ConversationOwner::Queued, 'queued_at' => Carbon::now()]);

        $this->assertFalse($this->cancelForSession(self::SESSION_A));
        $this->assertSame(ConversationOwner::Queued, Conversation::query()->sole()->owner_type);
    }

    /**
     * Fakes only the state event. The services are scoped and hold the dispatcher they were built with, so they are
     * built again against the fake.
     */
    private function fakeStateEvents(): void
    {
        Event::fake([OperatorRequestStateChanged::class]);
        $this->app->forgetScopedInstances();
    }

    private function requests(): OperatorRequestsInterface
    {
        return $this->app->make(OperatorRequestsInterface::class);
    }

    private function ownership(): ConversationOwnershipInterface
    {
        return $this->app->make(ConversationOwnershipInterface::class);
    }

    private function cancelForSession(string $sessionId): bool
    {
        return $this->requests()->cancelForSession(self::TENANT_ID, (string) $this->assistant->getKey(), (string) $this->contact->getKey(), $sessionId);
    }

    /**
     * Places a request by the default draft (the notification job is faked) and returns its id.
     */
    private function place(): string
    {
        Bus::fake([NotifyOperatorRequestJob::class]);

        $request = $this->requests()->place($this->draft())->request;
        $this->assertNotNull($request);

        return $request->id;
    }

    private function draft(
        ?Contact $contact = null,
        string $sessionId = self::SESSION_A,
        string $nodeId = self::NODE_A,
        bool $byEmail = false,
        ?string $note = null,
        string $connected = 'An operator connected',
        string $disconnected = 'The operator left',
    ): OperatorRequestDraft {
        return new OperatorRequestDraft(
            tenantId: self::TENANT_ID,
            assistantId: (string) $this->assistant->getKey(),
            contactId: (string) ($contact ?? $this->contact)->getKey(),
            sessionId: $sessionId,
            nodeId: $nodeId,
            recipients: ['target' => 'assistant', 'role' => null, 'user_ids' => []],
            byEmail: $byEmail,
            operatorNote: $note,
            connectedText: $connected,
            disconnectedText: $disconnected,
        );
    }

    /**
     * The contact's linkage to a new channel of the assistant.
     */
    private function channelFor(Contact $contact, bool $active, ?Assistant $assistant = null): Channel
    {
        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => ($assistant ?? $this->assistant)->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'is_active'    => $active,
        ]));

        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => Carbon::now(),
        ]);

        return $channel;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function thread(array $overrides = []): Conversation
    {
        return Conversation::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $this->assistant->getKey(),
            'contact_id'   => $this->contact->getKey(),
            'channel_id'   => $this->channel->getKey(),
            'platform'     => 'telegram',
            ...$overrides,
        ]);
    }
}
