<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Conversation\Contracts\ConversationOwnershipInterface;
use App\Domains\Conversation\Contracts\ConversationReplyServiceInterface;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Enums\ReplyOutcome;
use App\Domains\Conversation\Exceptions\ConversationHeldByAnotherOperatorException;
use App\Domains\Conversation\Exceptions\ConversationNotHeldByStaffException;
use App\Domains\Conversation\Exceptions\ConversationReplyUndeliverableException;
use App\Domains\Conversation\Exceptions\ConversationTransferTargetIneligibleException;
use App\Domains\Conversation\Live\ConversationActivityNotifier;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\ConversationMessage;
use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Exceptions\MediaDeletedException;
use App\Domains\Media\Exceptions\MediaNotFoundException;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Messaging\Exceptions\RateLimitExceededException;
use App\Domains\Messaging\Exceptions\UnsupportedChannelException;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\User;
use Fapost\Foundation\Quota\Exceptions\VolumeLimitReachedException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * The staff inbox of one assistant: which threads it lists, how many are unread, one thread's transcript, and what an
 * operator does with a thread (take it over, reply, finish it, transfer it, seize it).
 *
 * A thread's state is who handles it (see {@see ConversationOwner}). Who may act is decided here, not in a policy: a
 * policy only says an operator may reply in general, and an administrator passes every policy, so "this operator holds
 * this thread" is a query on the thread itself. Reply, finish and transfer need the thread held by the acting
 * operator; an administrator takes another operator's thread by seizing it first.
 *
 * Every query filters the tenant and the assistant itself (no global scope does it implicitly), and the shell's
 * unread badge counts through {@see self::unreadCount()} over the same {@see self::query()} the list uses, so the two
 * never disagree.
 *
 * A reply reaches a real person, so one submission is one message: the screen sends a `requestId` that stays the same
 * across retries of one draft, the first request reserves it here before anything is stored or sent, and the same id
 * becomes the message's idempotency key on the outbound path (volume gate and transcript included). A double click, a
 * retry or a replayed submit of the same draft sends nothing more (another tab writes its own draft, with its own id). A definite failure releases the reservation for a retry; an
 * ambiguous one (the provider may have the message) does not, and the operator is asked to check the transcript
 * ({@see self::reply()} has the exact rules).
 *
 * Reads the transcript through the Eloquent model, like the Filament view it replaced (the documented exception to the
 * storage port, see the conversation domain rules).
 */
final readonly class ConversationInbox
{
    /** How long a submission in flight stays reserved: longer than any request may run (a 20 MB upload included). */
    public const int PENDING_SECONDS = 600;

    /** How long a confirmed submission is remembered: longer than any retry an operator would make of one draft. */
    public const int SENT_SECONDS = 86400;

    private const string PENDING = 'pending';

    private const string SENT = 'sent';

    public function __construct(
        private ConversationOwnershipInterface $ownership,
        private ConversationReplyServiceInterface $replies,
        private MediaUploaderInterface $uploader,
        private MediaServiceInterface $media,
        private ConversationActivityNotifier $activity,
        private Cache $cache,
        private ExceptionHandler $exceptions,
    ) {
    }

    /**
     * The assistant's threads: the one query the list and the unread badge share.
     *
     * @return Builder<Conversation>
     */
    public function query(Assistant $assistant): Builder
    {
        return Conversation::query()
            ->where('conversations.tenant_id', (string) $assistant->tenant_id)
            ->where('conversations.assistant_id', (string) $assistant->getKey());
    }

    /**
     * Narrows `$query` to one handling bucket of the list: `queued` (waiting for an operator), `mine` (held by
     * `$staffUserId`), `staff` (held by any operator) or `bot` (the assistant answers).
     *
     * @param  Builder<Conversation>  $query
     *
     * @return bool whether `$handling` named a bucket
     */
    public function filterHandling(Builder $query, string $handling, string $staffUserId): bool
    {
        switch ($handling) {
            case 'queued':
                $query->where('conversations.owner_type', ConversationOwner::Queued->value);

                return true;
            case 'mine':
                $query->where('conversations.owner_type', ConversationOwner::Staff->value)
                    ->where('conversations.owner_staff_user_id', $staffUserId);

                return true;
            case 'staff':
                $query->where('conversations.owner_type', ConversationOwner::Staff->value);

                return true;
            case 'bot':
                $query->where(static fn (Builder $bucket) => $bucket
                    ->whereNull('conversations.owner_type')
                    ->orWhere('conversations.owner_type', ConversationOwner::Bot->value));

                return true;
            default:
                return false;
        }
    }

    /**
     * Threads with unread messages, for the navigation badge.
     */
    public function unreadCount(Assistant $assistant): int
    {
        return $this->query($assistant)->where('conversations.unread_count', '>', 0)->count();
    }

    /**
     * A thread of the assistant; another assistant's, another tenant's or a malformed id is not found.
     *
     * @throws ModelNotFoundException<Conversation>
     */
    public function find(Assistant $assistant, string $id): Conversation
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(Conversation::class, [$id]);
        }

        return $this->query($assistant)->with('contact')->whereKey($id)->firstOrFail();
    }

    /**
     * The latest `$limit` messages of the thread, oldest first.
     *
     * @return Collection<int, ConversationMessage>
     */
    public function transcript(Conversation $conversation, int $limit): Collection
    {
        return ConversationMessage::query()
            ->where('tenant_id', (string) $conversation->tenant_id)
            ->where('assistant_id', (string) $conversation->assistant_id)
            ->where('conversation_id', (string) $conversation->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * How the inbox names the contact of a thread: the name in the contact's meta, else `@username`, else the id the
     * channel knows them by.
     */
    public function contactLabel(Conversation $conversation): string
    {
        $contact = $conversation->contact;
        $meta    = is_array($contact?->meta) ? $contact->meta : [];

        $full = mb_trim((string) ($meta['first_name'] ?? '') . ' ' . (string) ($meta['last_name'] ?? ''));

        if ('' !== $full) {
            return $full;
        }

        if ('' !== (string) ($meta['username'] ?? '')) {
            return '@' . $meta['username'];
        }

        return null !== $contact ? (string) $contact->external_id : (string) $conversation->getKey();
    }

    /**
     * Display names of the operators among `$ids`, in one query.
     *
     * @param  list<string>  $ids
     *
     * @return array<string, string>
     */
    public function staffNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (string $id): bool => Str::isUuid($id))));

        if ([] === $ids) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = User::query()->whereIn('id', $ids)->pluck('name', 'id')->map(static fn (mixed $name): string => (string) $name)->all();

        return $names;
    }

    /**
     * A short-lived signed link to a media file of the current tenant, or null when it is not one (or is deleted). The
     * link names the file by id only: no disk, path or other tenant's file ever reaches the screen.
     */
    public function mediaUrl(string $mediaFileId): ?string
    {
        if (! Str::isUuid($mediaFileId)) {
            return null;
        }

        $file = $this->media->find($mediaFileId);

        return null !== $file ? $this->media->signedUrl($file) : null;
    }

    /**
     * Clears the unread counter; the caller has already authorized reading the thread.
     */
    public function markRead(Conversation $conversation): void
    {
        $this->ownership->markRead((string) $conversation->getKey());
    }

    /**
     * Accepts a thread nobody holds (the bot answers it, or it waits in the queue).
     *
     * @return bool whether this call took the thread over (false: an operator already holds it)
     */
    public function takeOver(Conversation $conversation, string $staffUserId): bool
    {
        return $this->announced($conversation, $this->ownership->takeOver((string) $conversation->getKey(), $staffUserId));
    }

    /**
     * The holder gives the thread back to the assistant.
     *
     * @return bool whether the thread went back (false: this operator does not hold it, any more or at all)
     */
    public function finish(Conversation $conversation, string $staffUserId): bool
    {
        return $this->announced($conversation, $this->ownership->finish((string) $conversation->getKey(), $staffUserId));
    }

    /**
     * The holder hands the thread to another operator, who does not confirm it.
     *
     * @return bool whether the thread moved (false: `$fromUserId` does not hold it, any more or at all)
     *
     * @throws ConversationTransferTargetIneligibleException `$toUserId` cannot take this thread
     */
    public function transfer(Assistant $assistant, Conversation $conversation, string $fromUserId, string $toUserId): bool
    {
        if ($fromUserId === $toUserId || null === $this->eligibleTarget($assistant, $toUserId)) {
            throw new ConversationTransferTargetIneligibleException("User [{$toUserId}] cannot take conversation [{$conversation->getKey()}].");
        }

        return $this->announced($conversation, $this->ownership->transfer((string) $conversation->getKey(), $fromUserId, $toUserId));
    }

    /**
     * An administrator takes a thread another operator holds. `$expectedOwnerId` is the holder the administrator saw,
     * so a thread that moved since is left alone.
     *
     * @return bool whether the thread was seized
     *
     * @throws AuthorizationException `$admin` is not an administrator
     */
    public function seize(Conversation $conversation, string $expectedOwnerId, User $admin): bool
    {
        if (! $admin->isAdmin()) {
            throw new AuthorizationException('Only an administrator can seize a conversation.');
        }

        if ((string) $admin->getKey() === $expectedOwnerId) {
            return false;
        }

        return $this->announced($conversation, $this->ownership->seize((string) $conversation->getKey(), $expectedOwnerId, (string) $admin->getKey()));
    }

    /**
     * The operators `$me` may hand a thread of the assistant to, by name.
     *
     * @return list<array{id: string, name: string}>
     */
    public function transferTargets(Assistant $assistant, User $me): array
    {
        return $this->eligibleStaff($assistant)
            ->reject(static fn (User $user): bool => (string) $user->getKey() === (string) $me->getKey())
            ->filter(fn (User $user): bool => $this->canTakeThreads($assistant, $user))
            ->sortBy(static fn (User $user): string => mb_strtolower((string) $user->name))
            ->map(static fn (User $user): array => ['id' => (string) $user->getKey(), 'name' => (string) $user->name])
            ->values()
            ->all();
    }

    /**
     * Sends one operator submission into the thread.
     *
     * The submission is reserved as `pending` (for {@see self::PENDING_SECONDS}) before anything is stored or sent, and
     * marked `sent` (for {@see self::SENT_SECONDS}) only once the provider confirmed it. A request that finds it `sent`
     * sends nothing ({@see ReplyOutcome::Duplicate}); one that finds it `pending` sends nothing either and says the
     * outcome is not confirmed yet ({@see ReplyOutcome::Unconfirmed}), as does an outbound path that answers the key is
     * a duplicate (it may only be marked in flight by an attempt that died mid-send).
     *
     * A definite failure (the volume gate refused, no active channel, a missing file, the channel's rate limit, the
     * provider rejected the message) releases the reservation and removes the attachment it stored, so the operator can
     * retry. Any other failure may have happened after the provider took the message (a read timeout, a dropped
     * connection, a Telegram upload that is itself the send): it is reported, the reservation is left to expire and the
     * attachment kept, and the operator is told to check the transcript ({@see ReplyOutcome::Unconfirmed}). So a double
     * click, a retry or a replayed submit of the same draft never sends twice; after an unconfirmed attempt, a retry once the pending
     * mark has expired can send a second copy if the first did arrive.
     *
     * @throws ConversationNotHeldByStaffException        nobody holds the thread (the bot answers it, or it is queued)
     * @throws ConversationHeldByAnotherOperatorException another operator holds the thread
     * @throws ConversationReplyUndeliverableException no active channel or chat to send to
     * @throws VolumeLimitReachedException             the tenant's outbound volume is used up
     * @throws Throwable                               a definite failure before anything was sent
     */
    public function reply(
        Conversation $conversation,
        string $staffUserId,
        string $requestId,
        string $text,
        ?UploadedFile $attachment = null,
    ): ReplyOutcome {
        if (ConversationOwner::Staff !== $conversation->owner_type) {
            throw new ConversationNotHeldByStaffException("Conversation [{$conversation->getKey()}] is not held by an operator.");
        }

        if ($staffUserId !== (string) $conversation->owner_staff_user_id) {
            throw new ConversationHeldByAnotherOperatorException("Conversation [{$conversation->getKey()}] is held by another operator.");
        }

        $reservation = "conversation-reply:{$conversation->tenant_id}:{$conversation->getKey()}:{$requestId}";

        if (! $this->cache->add($reservation, self::PENDING, self::PENDING_SECONDS)) {
            return self::SENT === $this->cache->get($reservation) ? ReplyOutcome::Duplicate : ReplyOutcome::Unconfirmed;
        }

        // The holder may have changed between loading the thread and taking the reservation.
        $stillHeld = Conversation::query()
            ->whereKey($conversation->getKey())
            ->where('owner_type', ConversationOwner::Staff->value)
            ->where('owner_staff_user_id', $staffUserId)
            ->exists();

        if (! $stillHeld) {
            $this->release($reservation, null);

            throw new ConversationHeldByAnotherOperatorException("Conversation [{$conversation->getKey()}] is held by another operator.");
        }

        $stored  = null;
        $sending = false;

        try {
            $stored = null !== $attachment ? $this->uploader->uploadFromUploadedFile(
                file: $attachment,
                folder: $this->media->findOrCreateInboxFolder(),
                name: $attachment->getClientOriginalName(),
                source: MediaSource::Upload,
                uploadedBy: $staffUserId,
            ) : null;

            $sending = true;
            $result  = $this->replies->send($conversation, $text, $staffUserId, null !== $stored ? (string) $stored->getKey() : null, $requestId);
        } catch (Throwable $exception) {
            // Storing the attachment failed (nothing was sent), or the failure is a definite one.
            if (! $sending || $this->isDefinite($exception)) {
                $this->release($reservation, $stored);

                throw $exception;
            }

            $this->exceptions->report($exception);

            return ReplyOutcome::Unconfirmed;
        }

        if ($result->sent) {
            $this->cache->put($reservation, self::SENT, self::SENT_SECONDS);

            return ReplyOutcome::Sent;
        }

        // The outbound path holds this key already: delivered, or still marked in flight by an attempt that died
        // mid-send. It cannot tell which, so neither can the inbox: nothing is recorded and the operator checks.
        if ($result->duplicate) {
            return ReplyOutcome::Unconfirmed;
        }

        // The provider answered and refused: nothing went out.
        $this->release($reservation, $stored);

        return ReplyOutcome::Failed;
    }

    /**
     * A failure that happened before the message could reach the provider, or that the provider answered.
     */
    private function isDefinite(Throwable $exception): bool
    {
        return $exception instanceof VolumeLimitReachedException
            || $exception instanceof ConversationReplyUndeliverableException
            || $exception instanceof MediaNotFoundException
            || $exception instanceof MediaDeletedException
            || $exception instanceof RateLimitExceededException
            || $exception instanceof UnsupportedChannelException;
    }

    private function release(string $reservation, ?MediaFile $stored): void
    {
        $this->cache->forget($reservation);

        if (null !== $stored) {
            $this->media->softDelete($stored);
        }
    }

    /**
     * Active, panel-eligible staff who work with the assistant (assigned to it, or administrators).
     *
     * @return Collection<int, User>
     */
    private function eligibleStaff(Assistant $assistant): Collection
    {
        return User::query()
            ->withoutPlatformSupport()
            ->where('is_active', true)
            ->where('status', UserStatus::Active->value)
            ->where(static fn (Builder $query) => $query
                ->whereHas('assistants', static fn (Builder $sub): Builder => $sub->whereKey($assistant->getKey()))
                ->orWhereHas('roles', static fn (Builder $sub): Builder => $sub->where('name', RoleEnum::Admin->value)))
            ->get();
    }

    private function eligibleTarget(Assistant $assistant, string $userId): ?User
    {
        return $this->eligibleStaff($assistant)
            ->first(fn (User $user): bool => (string) $user->getKey() === $userId && $this->canTakeThreads($assistant, $user));
    }

    private function canTakeThreads(Assistant $assistant, User $user): bool
    {
        return $user->can(Permission::ViewConversations->value)
            && $user->can(Permission::ReplyConversations->value)
            && $user->hasAssistantAccess($assistant);
    }

    private function announced(Conversation $conversation, bool $changed): bool
    {
        if ($changed) {
            $this->announce($conversation);
        }

        return $changed;
    }

    private function announce(Conversation $conversation): void
    {
        $this->activity->touched((string) $conversation->tenant_id, (string) $conversation->assistant_id);
    }
}
