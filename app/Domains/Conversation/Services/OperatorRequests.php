<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Services;

use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Conversation\Contracts\OperatorRequestsInterface;
use App\Domains\Conversation\DTO\OperatorRequest;
use App\Domains\Conversation\DTO\OperatorRequestDraft;
use App\Domains\Conversation\DTO\OperatorRequestPlacement;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Enums\ConversationStatus;
use App\Domains\Conversation\Enums\OperatorRequestPlacementOutcome;
use App\Domains\Conversation\Enums\OperatorRequestState;
use App\Domains\Conversation\Events\OperatorRequestStateChanged;
use App\Domains\Conversation\Jobs\NotifyOperatorRequestJob;
use App\Domains\Conversation\Live\ConversationActivityNotifier;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Settings\ConversationSettings;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Eloquent-backed operator requests over the tenant's operational database.
 *
 * Each change runs in a transaction that first locks the thread's row: the request body is JSON and is rewritten whole,
 * so it must be computed from what the row holds now. The write itself stays one conditional UPDATE on the handling
 * and the request id the lock saw, like every other ownership change.
 */
final readonly class OperatorRequests implements OperatorRequestsInterface
{
    public function __construct(
        private ConversationSettings $settings,
        private ConversationActivityNotifier $activity,
        private Events $events,
        private Bus $bus,
    ) {
    }

    public function place(OperatorRequestDraft $draft): OperatorRequestPlacement
    {
        // Without a transcript the operator would see an empty thread and never the contact's messages.
        if (! $this->settings->loggingEnabled()) {
            return OperatorRequestPlacement::refused(OperatorRequestPlacementOutcome::LoggingDisabled);
        }

        $channel = $this->latestChannel($draft);

        if (! $channel instanceof Channel) {
            return OperatorRequestPlacement::refused(OperatorRequestPlacementOutcome::NoChannel);
        }

        // The transcript job creates threads too, asynchronously: whoever comes first creates it (race-safe).
        $thread = Conversation::query()->createOrFirst(
            [
                'tenant_id'    => $draft->tenantId,
                'assistant_id' => $draft->assistantId,
                'contact_id'   => $draft->contactId,
                'channel_id'   => (string) $channel->getKey(),
            ],
            [
                'platform' => $channel->type,
                'status'   => ConversationStatus::Open->value,
                'meta'     => [],
            ],
        );

        $placement = $this->locked((string) $thread->getKey(), fn (Conversation $row): OperatorRequestPlacement => $this->placeOn($row, $draft, $channel->type));

        if (null === $placement) {
            return OperatorRequestPlacement::refused(OperatorRequestPlacementOutcome::NoChannel);
        }

        if (OperatorRequestPlacementOutcome::Placed === $placement->outcome && null !== $placement->request) {
            $this->activity->touched($draft->tenantId, $draft->assistantId);

            // Queued after the commit; the job delivers once per request, so a retried node notifies nobody twice.
            $this->bus->dispatch(new NotifyOperatorRequestJob(
                tenantId: $draft->tenantId,
                requestId: $placement->request->id,
                recipients: $draft->recipients,
                byEmail: $draft->byEmail,
                operatorNote: $draft->operatorNote,
            ));
        }

        return $placement;
    }

    public function attachMessage(string $requestId, string $messageId, string $messageSessionId): bool
    {
        return true === $this->lockedByRequest($requestId, static function (Conversation $row, OperatorRequest $request) use ($messageId, $messageSessionId): bool {
            if (OperatorRequestState::Pending !== $request->state) {
                return false;
            }

            return self::waiting($row, $request)->update([
                'operator_request' => $request->withMessage($messageId, $messageSessionId)->toJson(),
                'updated_at'       => Carbon::now(),
            ]) > 0;
        });
    }

    public function find(string $requestId): ?OperatorRequest
    {
        if (! Str::isUuid($requestId)) {
            return null;
        }

        $row = Conversation::query()->where('operator_request_id', $requestId)->first();

        return $row instanceof Conversation ? OperatorRequest::fromConversation($row) : null;
    }

    public function cancel(string $requestId): bool
    {
        return $this->release($requestId, OperatorRequestState::Cancelled);
    }

    public function cancelForSession(string $tenantId, string $assistantId, string $contactId, string $sessionId): bool
    {
        // A contact has a thread per channel; their queued ones are few, and the session is matched in PHP rather
        // than by a JSON condition the two database engines spell differently.
        $threads = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->where('assistant_id', $assistantId)
            ->where('contact_id', $contactId)
            ->where('owner_type', ConversationOwner::Queued->value)
            ->whereNotNull('operator_request_id')
            ->get();

        $cancelled = false;

        foreach ($threads as $thread) {
            $request = OperatorRequest::fromConversation($thread);

            if (null !== $request && OperatorRequestState::Pending === $request->state && $request->belongsToSession($sessionId)) {
                $cancelled = $this->cancel($request->id) || $cancelled;
            }
        }

        return $cancelled;
    }

    public function expire(string $requestId): bool
    {
        return $this->release($requestId, OperatorRequestState::Timeout);
    }

    /**
     * The thread while it still waits for this request.
     *
     * @return Builder<Conversation>
     */
    private static function waiting(Conversation $row, OperatorRequest $request): Builder
    {
        return Conversation::query()
            ->whereKey($row->getKey())
            ->where('owner_type', ConversationOwner::Queued->value)
            ->where('operator_request_id', $request->id);
    }

    private function placeOn(Conversation $row, OperatorRequestDraft $draft, string $channelType): ?OperatorRequestPlacement
    {
        $current = OperatorRequest::fromConversation($row);
        $now     = Carbon::now();

        // An operator already handles the thread: the session waits for them to finish.
        if (ConversationOwner::Staff === $row->owner_type && null !== $row->owner_staff_user_id) {
            if (null !== $current && OperatorRequestState::Accepted === $current->state && $current->sessionId === $draft->sessionId && $current->nodeId === $draft->nodeId) {
                return new OperatorRequestPlacement(OperatorRequestPlacementOutcome::Bound, $current, $channelType);
            }

            $request = $this->newRequest($row, $draft, OperatorRequestState::Accepted, (string) $row->owner_staff_user_id);

            $written = Conversation::query()
                ->whereKey($row->getKey())
                ->where('owner_type', ConversationOwner::Staff->value)
                ->where('owner_staff_user_id', $row->owner_staff_user_id)
                ->update([
                    'operator_request_id' => $request->id,
                    'operator_request'    => $request->toJson(),
                    'updated_at'          => $now,
                ]) > 0;

            return $written ? new OperatorRequestPlacement(OperatorRequestPlacementOutcome::Bound, $request, $channelType) : null;
        }

        // The thread already waits for an operator.
        if (ConversationOwner::Queued === $row->owner_type && null !== $current && OperatorRequestState::Pending === $current->state) {
            // The same node of the same session again (a retry before its marker was saved): the same request.
            if ($current->sessionId === $draft->sessionId && $current->nodeId === $draft->nodeId) {
                return new OperatorRequestPlacement(OperatorRequestPlacementOutcome::Placed, $current, $channelType);
            }

            // Another session asked before and is gone (a contact has one active session per assistant): this one
            // takes the waiting request over, with its own texts.
            $request = $current->boundTo($draft->sessionId, $draft->nodeId, $draft->connectedText, $draft->disconnectedText);

            $written = self::waiting($row, $current)->update([
                'operator_request' => $request->toJson(),
                'updated_at'       => $now,
            ]) > 0;

            return $written ? new OperatorRequestPlacement(OperatorRequestPlacementOutcome::Bound, $request, $channelType) : null;
        }

        // The bot answers the thread (or it is queued without a request of its own): it waits for an operator now.
        $request = $this->newRequest($row, $draft, OperatorRequestState::Pending, null);

        $written = Conversation::query()
            ->whereKey($row->getKey())
            ->where(static function (Builder $query) use ($row): void {
                null === $row->owner_type
                    ? $query->whereNull('owner_type')
                    : $query->where('owner_type', $row->owner_type->value);
            })
            ->where(static function (Builder $query) use ($row): void {
                null === $row->operator_request_id
                    ? $query->whereNull('operator_request_id')
                    : $query->where('operator_request_id', $row->operator_request_id);
            })
            ->update([
                'owner_type'          => ConversationOwner::Queued->value,
                'owner_staff_user_id' => null,
                'queued_at'           => $now,
                'operator_request_id' => $request->id,
                'operator_request'    => $request->toJson(),
                'updated_at'          => $now,
            ]) > 0;

        return $written ? new OperatorRequestPlacement(OperatorRequestPlacementOutcome::Placed, $request, $channelType) : null;
    }

    private function newRequest(Conversation $row, OperatorRequestDraft $draft, OperatorRequestState $state, ?string $acceptedBy): OperatorRequest
    {
        return new OperatorRequest(
            id: mb_strtolower((string) Str::ulid()->toRfc4122()),
            conversationId: (string) $row->getKey(),
            tenantId: (string) $row->tenant_id,
            assistantId: (string) $row->assistant_id,
            contactId: (string) $row->contact_id,
            platform: (string) $row->platform,
            state: $state,
            sessionId: $draft->sessionId,
            nodeId: $draft->nodeId,
            connectedText: $draft->connectedText,
            disconnectedText: $draft->disconnectedText,
            acceptedBy: $acceptedBy,
        );
    }

    /**
     * A pending request ends without an operator: the thread goes back to the bot.
     */
    private function release(string $requestId, OperatorRequestState $state): bool
    {
        $released = $this->lockedByRequest($requestId, static function (Conversation $row, OperatorRequest $request) use ($state): ?OperatorRequest {
            if (OperatorRequestState::Pending !== $request->state || ConversationOwner::Queued !== $row->owner_type) {
                return null;
            }

            $settled = $request->withState($state);

            $written = self::waiting($row, $request)->update([
                'owner_type'          => ConversationOwner::Bot->value,
                'owner_staff_user_id' => null,
                'queued_at'           => null,
                'operator_request'    => $settled->toJson(),
                'updated_at'          => Carbon::now(),
            ]) > 0;

            return $written ? $settled : null;
        });

        if (! $released instanceof OperatorRequest) {
            return false;
        }

        $this->activity->touched($released->tenantId, $released->assistantId);
        $this->events->dispatch(new OperatorRequestStateChanged($released->tenantId, $released->id, $state));

        return true;
    }

    private function latestChannel(OperatorRequestDraft $draft): ?Channel
    {
        // The channel the flow's own messages go out on (see FlowMessageSender): the contact's most recent active one.
        $channelContact = ChannelContact::query()
            ->select('channel_contacts.*')
            ->join('channels', 'channels.id', '=', 'channel_contacts.channel_id')
            ->where('channel_contacts.contact_id', $draft->contactId)
            ->where('channels.assistant_id', $draft->assistantId)
            ->where('channels.is_active', true)
            ->with('channel')
            ->orderByDesc('channel_contacts.last_interaction_at')
            ->first();

        return $channelContact instanceof ChannelContact && $channelContact->channel instanceof Channel ? $channelContact->channel : null;
    }

    /**
     * @template T
     *
     * @param  callable(Conversation): T  $callback
     *
     * @return T|null null when the thread is gone
     */
    private function locked(string $conversationId, callable $callback): mixed
    {
        $connection = (new Conversation())->getConnection();

        return $connection->transaction(static function () use ($conversationId, $callback): mixed {
            $row = Conversation::query()->whereKey($conversationId)->lockForUpdate()->first();

            return $row instanceof Conversation ? $callback($row) : null;
        });
    }

    /**
     * @template T
     *
     * @param  callable(Conversation, OperatorRequest): T  $callback
     *
     * @return T|null null when no thread holds the request
     */
    private function lockedByRequest(string $requestId, callable $callback): mixed
    {
        if (! Str::isUuid($requestId)) {
            return null;
        }

        $connection = (new Conversation())->getConnection();

        return $connection->transaction(static function () use ($requestId, $callback): mixed {
            $row     = Conversation::query()->where('operator_request_id', $requestId)->lockForUpdate()->first();
            $request = $row instanceof Conversation ? OperatorRequest::fromConversation($row) : null;

            return $row instanceof Conversation && null !== $request ? $callback($row, $request) : null;
        });
    }
}
