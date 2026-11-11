<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Notifications;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Enums\OperatorRequestState;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Services\ConversationOperators;
use App\Domains\Conversation\Support\ContactLabel;
use App\Domains\Staff\Live\StaffNotificationsAnnouncer;
use App\Domains\Staff\Models\User;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Puts what happens to an operator's threads in their bell (in-app only):
 *
 * - a new message from the contact in a thread they hold — at most one unread notification per thread: while one is
 *   unread, further messages add nothing; once it is read, the next message brings a new one;
 * - a thread another operator transferred to them;
 * - a thread an administrator seized from them;
 * - a contact waiting for an operator (a flow's operator request), to everyone the request goes to. Once the request
 *   stops waiting — accepted, cancelled or timed out — each of those notifications is marked read and rendered again
 *   with the outcome ("Accepted by Y"), the accepting operator's own included: an unread one would hold back their
 *   message notifications for the thread.
 *
 * Never throws: a failure is reported, so it never fails the transcript job (the logger never throws to its caller),
 * the operator's transfer or seizure, which has already happened, or the request. A recipient who may not read the
 * thread — not active, switched off, without `ViewConversations` or without access to its assistant — gets nothing.
 */
final readonly class ConversationOperatorNotifier
{
    /** How long one thread's check-and-write may hold its lock. */
    private const int LOCK_SECONDS = 10;

    public function __construct(
        private Cache $cache,
        private UrlGenerator $urls,
        private ExceptionHandler $exceptions,
        private ConversationOperators $operators,
        private StaffNotificationsAnnouncer $announcer,
    ) {
    }

    /**
     * A message from the contact was recorded in the thread: its holder hears of it, unless a notification about the
     * thread is still unread. Runs inside the tenant switch of the transcript job.
     */
    public function messageReceived(string $tenantId, string $conversationId): void
    {
        try {
            $conversation = Conversation::query()->with('contact')->whereKey($conversationId)->first();

            if (! $conversation instanceof Conversation || ConversationOwner::Staff !== $conversation->owner_type || null === $conversation->owner_staff_user_id) {
                return;
            }

            $holder = $this->recipient((string) $conversation->owner_staff_user_id, $conversation);

            if (null === $holder) {
                return;
            }

            // Two transcript workers recording two messages of one thread at once: the one that does not get the lock
            // leaves it to the one that does, which writes the single unread notification.
            $this->exclusively("conversation-operator-notify:{$tenantId}:{$conversationId}:{$holder->getKey()}", function () use ($holder, $conversation): void {
                if (! $this->hasUnread($holder, (string) $conversation->getKey())) {
                    $this->send($holder, ConversationOperatorNotification::MESSAGE, $conversation, null);
                }
            });
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }

    /**
     * The thread moved from `$fromUserId` to `$toUserId`: the new holder hears who handed it over.
     */
    public function transferred(Conversation $conversation, string $toUserId, string $fromUserId): void
    {
        try {
            $recipient = $this->recipient($toUserId, $conversation);

            if (null !== $recipient) {
                $this->send($recipient, ConversationOperatorNotification::TRANSFERRED, $conversation, $this->name($fromUserId));
            }
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }

    /**
     * An administrator took the thread from `$previousOwnerId`, who hears who took it.
     */
    public function seized(Conversation $conversation, string $previousOwnerId, User $admin): void
    {
        try {
            $recipient = $this->recipient($previousOwnerId, $conversation);

            if (null !== $recipient) {
                $this->send($recipient, ConversationOperatorNotification::SEIZED, $conversation, (string) $admin->name);
            }
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }

    /**
     * A contact waits for an operator: `$recipient`, chosen by the request, hears of it in their bell. The caller checked
     * that they may take the assistant's threads.
     */
    public function requested(User $recipient, Conversation $conversation, string $requestId, ?string $note): void
    {
        try {
            $this->send($recipient, ConversationOperatorNotification::REQUESTED, $conversation, null, $requestId, $note);
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }

    /**
     * The request stopped waiting: every notification about it is marked read and rendered again with the outcome, and
     * the recipients' bells are told.
     */
    public function settled(string $requestId, OperatorRequestState $state, ?string $actorUserId): void
    {
        $outcome = $state->value;

        if (! in_array($outcome, ConversationOperatorNotification::OUTCOMES, true)) {
            return;
        }

        try {
            $actor = null !== $actorUserId ? $this->name($actorUserId) : null;

            // `data` is a text column: narrowed by the id it contains, then compared exactly here (see hasUnread()).
            $notifications = DatabaseNotification::query()
                ->where('type', ConversationOperatorNotification::class)
                ->where('data', 'like', '%' . $requestId . '%')
                ->get();

            foreach ($notifications as $notification) {
                $data = $notification->data;

                if (! is_array($data) || ($data['request_id'] ?? null) !== $requestId || ConversationOperatorNotification::REQUESTED !== ($data['kind'] ?? null)) {
                    continue;
                }

                $user = $notification->notifiable;

                $notification->forceFill([
                    'data'    => ConversationOperatorNotification::settle($data, $outcome, $actor, $user instanceof User ? $user->preferredLocale() : null),
                    'read_at' => $notification->read_at ?? Carbon::now(),
                ])->save();

                if ($user instanceof User) {
                    $this->announcer->changed($user);
                }
            }
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }

    private function send(User $recipient, string $kind, Conversation $conversation, ?string $actor, ?string $requestId = null, ?string $note = null): void
    {
        $url = $this->urls->route('console.conversations.show', [
            'assistant' => (string) $conversation->assistant_id,
            'record'    => (string) $conversation->getKey(),
        ], false);

        $recipient->notifyNow(new ConversationOperatorNotification(
            $kind,
            (string) $conversation->getKey(),
            ContactLabel::of($conversation),
            $url,
            $actor,
            $requestId,
            $note,
        ));
    }

    /**
     * Whether the user has an unread notification about the thread. `data` is a text column, so the rows of this
     * notification are read and compared here rather than by a JSON operator the two database engines spell
     * differently; a user has few unread ones.
     */
    private function hasUnread(User $user, string $conversationId): bool
    {
        return $user->unreadNotifications()
            ->where('type', ConversationOperatorNotification::class)
            ->get()
            ->contains(static function (DatabaseNotification $notification) use ($conversationId): bool {
                $data = $notification->data;

                return is_array($data) && ($data['conversation_id'] ?? null) === $conversationId;
            });
    }

    /**
     * @param  callable(): void  $callback
     */
    private function exclusively(string $key, callable $callback): void
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            $callback();

            return;
        }

        $store->lock($key, self::LOCK_SECONDS)->get($callback);
    }

    /**
     * The user, when they may still open the thread the notification links to: access is checked again here, since it
     * may have changed since they took the thread (an administrator unassigned them from the assistant).
     */
    private function recipient(string $userId, Conversation $conversation): ?User
    {
        $user      = '' === $userId ? null : User::query()->find($userId);
        $assistant = Assistant::query()->find((string) $conversation->assistant_id);

        // The platform support user is not one of the tenant's people (support access ADR); the check says so too.
        return $user instanceof User && $assistant instanceof Assistant && $this->operators->canReadThreads($assistant, $user) ? $user : null;
    }

    private function name(string $userId): ?string
    {
        $name = User::query()->whereKey($userId)->value('name');

        return is_string($name) ? $name : null;
    }
}
