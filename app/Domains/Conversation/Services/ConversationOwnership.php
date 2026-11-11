<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Services;

use App\Domains\Conversation\Contracts\ConversationOwnershipInterface;
use App\Domains\Conversation\DTO\ConversationRef;
use App\Domains\Conversation\DTO\OperatorRequest;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Enums\OperatorRequestState;
use App\Domains\Conversation\Events\OperatorRequestStateChanged;
use App\Domains\Conversation\Models\Conversation;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Eloquent-backed thread ownership over the tenant's operational database.
 *
 * Reads the same columns the logging migration reserved for the Inbox
 * (`owner_type`, `owner_staff_user_id`) plus `queued_at`, and the unread counter
 * the capture pipeline increments. Every write is one conditional UPDATE, so
 * the database decides a race, not the application.
 *
 * Taking over a `queued` thread accepts its operator request, and finishing a thread whose request was accepted returns
 * it: the request body is rewritten in the same UPDATE, computed under a lock on the thread's row (see
 * {@see OperatorRequests}), and the change is announced after the commit.
 */
final readonly class ConversationOwnership implements ConversationOwnershipInterface
{
    public function __construct(
        private Events $events,
    ) {
    }

    public function isHandledByStaff(ConversationRef $ref): bool
    {
        return Conversation::query()
            ->where('tenant_id', $ref->tenantId)
            ->where('assistant_id', $ref->assistantId)
            ->where('contact_id', $ref->contactId)
            ->where('channel_id', $ref->channelId)
            ->whereIn('owner_type', [ConversationOwner::Staff->value, ConversationOwner::Queued->value])
            ->exists();
    }

    public function takeOver(string $conversationId, string $staffUserId): bool
    {
        return $this->withRequest($conversationId, function (?Conversation $row, ?OperatorRequest $request) use ($conversationId, $staffUserId): array {
            $values = [
                'owner_type'          => ConversationOwner::Staff->value,
                'owner_staff_user_id' => $staffUserId,
                'queued_at'           => null,
                'updated_at'          => Carbon::now(),
            ];

            // Accepting the request the thread waits for. A request that timed out or was cancelled first stays as it
            // is: the flow has moved on, and this is an ordinary takeover.
            $accepting = null !== $row
                && null !== $request
                && ConversationOwner::Queued === $row->owner_type
                && OperatorRequestState::Pending === $request->state;

            if ($accepting) {
                $accepted = $request->withState(OperatorRequestState::Accepted, $staffUserId);

                $changed = Conversation::query()
                    ->whereKey($conversationId)
                    ->where('owner_type', ConversationOwner::Queued->value)
                    ->where('operator_request_id', $request->id)
                    ->update($values + ['operator_request' => $accepted->toJson()]) > 0;

                return [$changed, $changed ? $accepted : null];
            }

            $changed = Conversation::query()
                ->whereKey($conversationId)
                ->where(static fn (Builder $query) => $query
                    ->whereNull('owner_type')
                    ->orWhereIn('owner_type', [ConversationOwner::Bot->value, ConversationOwner::Queued->value]))
                ->update($values) > 0;

            return [$changed, null];
        }, $staffUserId);
    }

    public function finish(string $conversationId, string $staffUserId): bool
    {
        return $this->withRequest($conversationId, function (?Conversation $row, ?OperatorRequest $request) use ($conversationId, $staffUserId): array {
            $values = [
                'owner_type'          => ConversationOwner::Bot->value,
                'owner_staff_user_id' => null,
                'queued_at'           => null,
                'updated_at'          => Carbon::now(),
            ];

            $returning = null !== $request && OperatorRequestState::Accepted === $request->state;
            $returned  = $returning ? $request->withState(OperatorRequestState::Returned) : null;

            $query = $this->heldBy($conversationId, $staffUserId);

            if (null !== $returned) {
                $query->where('operator_request_id', $returned->id);
                $values['operator_request'] = $returned->toJson();
            }

            $changed = $query->update($values) > 0;

            return [$changed, $changed ? $returned : null];
        }, $staffUserId);
    }

    public function transfer(string $conversationId, string $fromUserId, string $toUserId): bool
    {
        return $this->heldBy($conversationId, $fromUserId)
            ->update([
                'owner_staff_user_id' => $toUserId,
                'updated_at'          => Carbon::now(),
            ]) > 0;
    }

    public function seize(string $conversationId, string $expectedOwnerId, string $adminUserId): bool
    {
        return $this->heldBy($conversationId, $expectedOwnerId)
            ->update([
                'owner_staff_user_id' => $adminUserId,
                'updated_at'          => Carbon::now(),
            ]) > 0;
    }

    public function markRead(string $conversationId): void
    {
        Conversation::query()
            ->whereKey($conversationId)
            ->where('unread_count', '>', 0)
            ->update([
                'unread_count' => 0,
                'updated_at'   => Carbon::now(),
            ]);
    }

    /**
     * Runs `$change` on the thread's row locked for the transaction, and announces the request's new state once the
     * change is committed.
     *
     * @param  callable(?Conversation, ?OperatorRequest): array{0: bool, 1: OperatorRequest|null}  $change
     */
    private function withRequest(string $conversationId, callable $change, string $actorUserId): bool
    {
        [$changed, $settled] = (new Conversation())->getConnection()->transaction(static function () use ($conversationId, $change): array {
            $row = Conversation::query()->whereKey($conversationId)->lockForUpdate()->first();

            return $change($row, $row instanceof Conversation ? OperatorRequest::fromConversation($row) : null);
        });

        if ($settled instanceof OperatorRequest) {
            $this->events->dispatch(new OperatorRequestStateChanged($settled->tenantId, $settled->id, $settled->state, $actorUserId));
        }

        return $changed;
    }

    /**
     * The thread, but only while this operator holds it.
     *
     * @return Builder<Conversation>
     */
    private function heldBy(string $conversationId, string $staffUserId): Builder
    {
        return Conversation::query()
            ->whereKey($conversationId)
            ->where('owner_type', ConversationOwner::Staff->value)
            ->where('owner_staff_user_id', $staffUserId);
    }
}
