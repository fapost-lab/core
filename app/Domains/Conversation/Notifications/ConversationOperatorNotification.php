<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Notifications;

use App\Domains\Staff\Contracts\LocalizedDatabaseNotification;
use App\Domains\Staff\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Tells an operator about a thread that is theirs: a new message from the contact (`conversation_message`), a thread
 * another operator handed them (`conversation_transferred`), or a thread an administrator took from them
 * (`conversation_seized`). Links to the thread.
 *
 * Sent with `notifyNow` by {@see ConversationOperatorNotifier}, never queued: from the transcript job it runs inside the
 * tenant switch, and a queued notification would restore the user outside the tenant. Stored in the shape the bell
 * reads — `title`, `body`, Core's `action`, `kind` — plus what it is about (`conversation_id`, `contact`, `actor`), so
 * the bell renders it again in the language of the page ({@see LocalizedDatabaseNotification}) and the notifier finds
 * the unread one of a thread.
 */
final class ConversationOperatorNotification extends Notification implements LocalizedDatabaseNotification
{
    public const string MESSAGE = 'conversation_message';

    public const string TRANSFERRED = 'conversation_transferred';

    public const string SEIZED = 'conversation_seized';

    /**
     * @param  string       $kind     one of {@see self::MESSAGE}, {@see self::TRANSFERRED}, {@see self::SEIZED}
     * @param  string       $url      the thread's path on the tenant's host
     * @param  string|null  $actor    who handed the thread over or took it, for the two ownership kinds
     */
    public function __construct(
        private readonly string $kind,
        private readonly string $conversationId,
        private readonly string $contact,
        private readonly string $url,
        private readonly ?string $actor = null,
    ) {
    }

    public static function localize(array $data, string $locale): array
    {
        $kind    = $data['kind'] ?? null;
        $contact = $data['contact'] ?? null;
        $actor   = $data['actor'] ?? null;

        if (! is_string($kind) || ! is_string($contact) || ! in_array($kind, [self::MESSAGE, self::TRANSFERRED, self::SEIZED], true)) {
            return [];
        }

        return self::text($kind, $contact, is_string($actor) ? $actor : null, $locale);
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, body: string, action: array{label: string, url: string}, kind: string, conversation_id: string, contact: string, actor: string|null}
     */
    public function toArray(User $notifiable): array
    {
        $text = self::text($this->kind, $this->contact, $this->actor, $notifiable->preferredLocale());

        return [
            'title'  => $text['title'],
            'body'   => $text['body'],
            'action' => [
                'label' => $text['actionLabel'],
                'url'   => $this->url,
            ],
            'kind'            => $this->kind,
            'conversation_id' => $this->conversationId,
            'contact'         => $this->contact,
            'actor'           => $this->actor,
        ];
    }

    /**
     * @return array{title: string, body: string, actionLabel: string}
     */
    private static function text(string $kind, string $contact, ?string $actor, ?string $locale): array
    {
        $key = 'conversation.operator_notifications.' . match ($kind) {
            self::TRANSFERRED => 'transferred',
            self::SEIZED      => 'seized',
            default           => 'message',
        };
        $parameters = ['contact' => $contact, 'actor' => $actor ?? '—'];

        return [
            'title'       => (string) trans($key . '.title', $parameters, $locale),
            'body'        => (string) trans($key . '.body', $parameters, $locale),
            'actionLabel' => (string) trans('conversation.operator_notifications.open', [], $locale),
        ];
    }
}
