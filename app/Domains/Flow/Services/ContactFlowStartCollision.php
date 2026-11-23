<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Conversation\Contracts\ConversationOwnershipInterface;
use App\Domains\Conversation\DTO\ConversationRef;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowSession;
use Illuminate\Support\Carbon;

/**
 * Decides whether a conversation may be started for a contact who may already be talking to the bot — by a `start_flow`
 * node or by a flow event's trigger ({@see \App\Jobs\Flow\StartFlowFromEventJob}).
 *
 * Busy — nothing is started — when the thread the start would write to is handled by an operator (or waits for one),
 * when the live conversation is the one that asked for the start (a flow naming its own contact never ends itself),
 * when a live conversation sleeps in a `delay` (it is due to move on its own), or when a live conversation of the
 * contact with this assistant moved within the busy window (the node's, or the fixed default for an event start). A live
 * conversation that has not moved within the window is stale: the start ends it first ({@see FlowSessionTerminator}),
 * so the contact never has two live conversations with one bot. A window of 0 never makes the contact busy by time.
 *
 * Asked by the node, for a visible `busy` exit, and by its job under the contact's lock, whose answer holds; an event
 * start asks once, under the lock, with the emitting conversation as the asking one.
 */
final readonly class ContactFlowStartCollision
{
    public const string REASON_OPERATOR = 'operator';

    public const string REASON_CONVERSATION_MOVING = 'conversation_moving';

    public const string REASON_OWN_CONVERSATION = 'own_conversation';

    public const string REASON_CONVERSATION_SCHEDULED = 'conversation_scheduled';

    /** The busy window, in minutes, of an event start and the default of a `start_flow` node's field. */
    public const int DEFAULT_BUSY_WINDOW_MINUTES = 30;

    /** How far up a subflow chain the asking session is followed; deeper chains are refused at publish. */
    private const int MAX_CHAIN_WALK = 16;

    public function __construct(
        private ConversationOwnershipInterface $ownership,
    ) {
    }

    /**
     * @param  ConversationRef  $thread           the thread the started conversation would write to
     * @param  int              $windowMinutes    the busy window; 0 = never busy by time
     * @param  string|null      $askingSessionId  the session whose node asks for the start, or that emitted the event
     */
    public function assess(ConversationRef $thread, int $windowMinutes, ?string $askingSessionId): ContactFlowStartVerdict
    {
        if ($this->ownership->isHandledByStaff($thread)) {
            return ContactFlowStartVerdict::busy(self::REASON_OPERATOR);
        }

        /** @var list<FlowSession> $live */
        $live = FlowSession::query()
            ->where('tenant_id', $thread->tenantId)
            ->where('assistant_id', $thread->assistantId)
            ->where('contact_id', $thread->contactId)
            ->whereIn('status', FlowSessionStatus::inFlightValues())
            ->get()
            ->all();

        if ([] === $live) {
            return ContactFlowStartVerdict::free([]);
        }

        $asking = $this->askingChain($askingSessionId);

        foreach ($live as $session) {
            if (in_array((string) $session->getKey(), $asking, true)) {
                return ContactFlowStartVerdict::busy(self::REASON_OWN_CONVERSATION);
            }
        }

        // A conversation asleep in a `delay` is due to move on its own (a follow-up, a reminder): never ended by a start.
        foreach ($live as $session) {
            if (FlowSessionStatus::Paused === $session->status) {
                return ContactFlowStartVerdict::busy(self::REASON_CONVERSATION_SCHEDULED);
            }
        }

        if ($windowMinutes > 0) {
            $cutoff = Carbon::now()->subMinutes($windowMinutes);

            foreach ($live as $session) {
                if (null === $session->updated_at || $session->updated_at->greaterThanOrEqualTo($cutoff)) {
                    return ContactFlowStartVerdict::busy(self::REASON_CONVERSATION_MOVING);
                }
            }
        }

        return ContactFlowStartVerdict::free($live);
    }

    /**
     * The asking session and the parents above it: a subflow child asks inside its parent's conversation.
     *
     * @return list<string>
     */
    private function askingChain(?string $sessionId): array
    {
        $chain = [];

        for ($depth = 0; is_string($sessionId) && '' !== $sessionId && $depth < self::MAX_CHAIN_WALK; ++$depth) {
            if (in_array($sessionId, $chain, true)) {
                break;
            }

            $chain[] = $sessionId;

            $parentId  = FlowSession::query()->whereKey($sessionId)->value('parent_session_id');
            $sessionId = is_string($parentId) ? $parentId : null;
        }

        return $chain;
    }
}
