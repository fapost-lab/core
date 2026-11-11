<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Notifications;

use App\Domains\Staff\Contracts\LocalizedDatabaseNotification;
use App\Domains\Staff\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Tells an operator about a thread: a new message from the contact in a thread they hold (`conversation_message`), a
 * thread another operator handed them (`conversation_transferred`), a thread an administrator took from them
 * (`conversation_seized`), or a contact waiting for an operator (`conversation_requested`). Links to the thread.
 *
 * Sent with `notifyNow` by {@see ConversationOperatorNotifier}, never queued: from a job it runs inside the tenant
 * switch, and a queued notification would restore the user outside the tenant. Stored in the shape the bell reads —
 * `title`, `body`, Core's `action`, `kind` — plus what it is about (`conversation_id`, `contact`, `actor`, and for a
 * request `request_id`, the node's `note` and, once the request stopped waiting, its `outcome`), so the bell renders it
 * again in the language of the page ({@see LocalizedDatabaseNotification}) and the notifier finds the rows of a thread
 * or of a request.
 */
final class ConversationOperatorNotification extends Notification implements LocalizedDatabaseNotification
{
    public const string MESSAGE = 'conversation_message';

    public const string TRANSFERRED = 'conversation_transferred';

    public const string SEIZED = 'conversation_seized';

    public const string REQUESTED = 'conversation_requested';

    /** The outcomes a request notification is rendered again with once the request stopped waiting. */
    public const array OUTCOMES = ['accepted', 'cancelled', 'timeout'];

    private const array KINDS = [self::MESSAGE, self::TRANSFERRED, self::SEIZED, self::REQUESTED];

    /**
     * @param  string       $kind       one of {@see self::MESSAGE}, {@see self::TRANSFERRED}, {@see self::SEIZED},
     *                                  {@see self::REQUESTED}
     * @param  string       $url        the thread's path on the tenant's host
     * @param  string|null  $actor      who handed the thread over or took it, for the two ownership kinds
     * @param  string|null  $requestId  the operator request, for {@see self::REQUESTED}
     * @param  string|null  $note       the request node's own text for the operators; null gives the standard one
     */
    public function __construct(
        private readonly string $kind,
        private readonly string $conversationId,
        private readonly string $contact,
        private readonly string $url,
        private readonly ?string $actor = null,
        private readonly ?string $requestId = null,
        private readonly ?string $note = null,
    ) {
    }

    public static function localize(array $data, string $locale): array
    {
        $kind    = $data['kind'] ?? null;
        $contact = $data['contact'] ?? null;

        if (! is_string($kind) || ! is_string($contact) || ! in_array($kind, self::KINDS, true)) {
            return [];
        }

        return self::text(
            $kind,
            $contact,
            self::stringOrNull($data['actor'] ?? null),
            self::stringOrNull($data['note'] ?? null),
            self::stringOrNull($data['outcome'] ?? null),
            $locale,
        );
    }

    /**
     * The stored row of a request notification once the request stopped waiting: rendered again with its outcome.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    public static function settle(array $data, string $outcome, ?string $actor, ?string $locale): array
    {
        $data['outcome'] = $outcome;
        $data['actor']   = $actor;

        $text = self::text(
            self::REQUESTED,
            is_string($data['contact'] ?? null) ? $data['contact'] : '',
            $actor,
            self::stringOrNull($data['note'] ?? null),
            $outcome,
            $locale,
        );

        $data['title'] = $text['title'];
        $data['body']  = $text['body'];

        return $data;
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, body: string, action: array{label: string, url: string}, kind: string, conversation_id: string, contact: string, actor: string|null, request_id?: string|null, note?: string|null, outcome?: null}
     */
    public function toArray(User $notifiable): array
    {
        $text = self::text($this->kind, $this->contact, $this->actor, $this->note, null, $notifiable->preferredLocale());

        $data = [
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

        if (self::REQUESTED === $this->kind) {
            $data['request_id'] = $this->requestId;
            $data['note']       = $this->note;
            $data['outcome']    = null;
        }

        return $data;
    }

    /**
     * @return array{title: string, body: string, actionLabel: string}
     */
    private static function text(string $kind, string $contact, ?string $actor, ?string $note, ?string $outcome, ?string $locale): array
    {
        $key = 'conversation.operator_notifications.' . match ($kind) {
            self::TRANSFERRED => 'transferred',
            self::SEIZED      => 'seized',
            self::REQUESTED   => 'requested',
            default           => 'message',
        };
        $parameters = ['contact' => $contact, 'actor' => $actor ?? '—'];

        $body = match (true) {
            self::REQUESTED === $kind && in_array($outcome, self::OUTCOMES, true) => (string) trans($key . '.outcomes.' . $outcome, $parameters, $locale),
            self::REQUESTED === $kind && null !== $note                           => $note,
            default                                                               => (string) trans($key . '.body', $parameters, $locale),
        };

        return [
            'title'       => (string) trans($key . '.title', $parameters, $locale),
            'body'        => $body,
            'actionLabel' => (string) trans('conversation.operator_notifications.open', [], $locale),
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
