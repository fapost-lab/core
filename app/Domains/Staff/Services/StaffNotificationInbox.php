<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Contracts\LocalizedDatabaseNotification;
use App\Domains\Staff\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The signed-in staff user's in-app notifications (the `notifications` table of the tenant's schema): how many are
 * unread, the latest ones in the shape the shell's bell shows, and marking them read.
 *
 * Every query goes through the user's own `notifications()` relation, never through a policy: admins pass every policy
 * through `Gate::before`, and nobody — an admin included — reads or marks another user's notifications. Another user's
 * id, or one that is not a uuid, is a 404. The tenant boundary is the schema the request already runs in.
 *
 * Rows come from Core's {@see \App\Domains\Staff\Notifications\StaffMessageNotification} and
 * {@see \App\Domains\Staff\Notifications\LimitReachedNotification} and from Filament before it left; all of them keep
 * Filament's keys (`title`, `body`, `status`, `iconColor`, `actions`) or Core's `action`. The text is returned plain:
 * markup in a stored body is removed, never rendered. A row whose class implements {@see LocalizedDatabaseNotification}
 * is rendered again in the language of the request.
 */
final class StaffNotificationInbox
{
    /**
     * How many notifications the bell lists.
     */
    public const int LATEST = 20;

    private const array TONES = ['success', 'info', 'warning', 'danger'];

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    /**
     * The latest notifications, newest first.
     *
     * @return list<array{id: string, title: string, body: string|null, tone: string, action: array{label: string|null, url: string}|null, createdAt: string|null, read: bool}>
     */
    public function latest(User $user, int $limit = self::LATEST): array
    {
        return $user->notifications()
            ->limit($limit)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => $this->present($notification))
            ->values()
            ->all();
    }

    /**
     * Marks one of the user's own notifications read; reading it twice changes nothing.
     */
    public function markRead(User $user, string $id): void
    {
        $notification = Str::isUuid($id) ? $user->notifications()->whereKey($id)->first() : null;

        if (! $notification instanceof DatabaseNotification) {
            throw new NotFoundHttpException();
        }

        $notification->markAsRead();
    }

    /**
     * Marks every unread notification of the user read, in one query.
     */
    public function markAllRead(User $user): void
    {
        $user->unreadNotifications()->update(['read_at' => now()]);
    }

    /**
     * @return array{id: string, title: string, body: string|null, tone: string, action: array{label: string|null, url: string}|null, createdAt: string|null, read: bool}
     */
    private function present(DatabaseNotification $notification): array
    {
        $data      = $notification->data;
        $data      = is_array($data) ? $data : [];
        $localized = $this->localized($notification->type, $data);
        $action    = $this->action($data);

        if (null !== $action && isset($localized['actionLabel'])) {
            $action['label'] = $this->text($localized['actionLabel']);
        }

        return [
            'id'        => (string) $notification->getKey(),
            'title'     => $this->text($localized['title'] ?? $data['title'] ?? null) ?? '',
            'body'      => $this->text($localized['body'] ?? $data['body'] ?? null),
            'tone'      => $this->tone($data),
            'action'    => $action,
            'createdAt' => $notification->created_at?->toAtomString(),
            'read'      => null !== $notification->read_at,
        ];
    }

    /**
     * The row's text in the language of the request, when its class can render it; the stored type is only trusted
     * when it names such a class.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{title?: string, body?: string, actionLabel?: string}
     */
    private function localized(mixed $type, array $data): array
    {
        if (! is_string($type) || ! is_a($type, LocalizedDatabaseNotification::class, true)) {
            return [];
        }

        return $type::localize($data, app()->getLocale());
    }

    /**
     * Plain text: tags removed and entities decoded, whitespace collapsed; nothing left is `null`.
     */
    private function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_trim((string) preg_replace('/\s+/u', ' ', $text));

        return '' === $text ? null : $text;
    }

    /**
     * Filament's `status` (or its `iconColor`), a limit notice is a warning, anything else neutral.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function tone(array $data): string
    {
        foreach (['status', 'iconColor'] as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && in_array($value, self::TONES, true)) {
                return $value;
            }
        }

        return 'limit_reached' === ($data['kind'] ?? null) ? 'warning' : 'neutral';
    }

    /**
     * Core's `action`, else the first of Filament's `actions` that has a URL. Only a web address or a path on this host
     * is kept, so a stored `javascript:` link never becomes a link.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{label: string|null, url: string}|null
     */
    private function action(array $data): ?array
    {
        $candidates = [];

        if (is_array($data['action'] ?? null)) {
            $candidates[] = $data['action'];
        }

        if (is_array($data['actions'] ?? null)) {
            foreach ($data['actions'] as $action) {
                if (is_array($action)) {
                    $candidates[] = $action;
                }
            }
        }

        foreach ($candidates as $candidate) {
            $url = $candidate['url'] ?? null;

            if (is_string($url) && $this->isSafeUrl($url)) {
                return ['label' => $this->text($candidate['label'] ?? null), 'url' => $url];
            }
        }

        return null;
    }

    /**
     * A path on this host (`/…`, never `//…`) or an `http(s)` address on any host: a limit notice links to the tenant's
     * panel or to the operator's own page. Whitespace, control characters and backslashes are refused anywhere, since
     * browsers drop or reinterpret them (`/<tab>/evil.test` and `/\evil.test` both open another host).
     */
    private function isSafeUrl(string $url): bool
    {
        if ('' === $url || 1 === preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }

        return 1 === preg_match('#^https?://[^/?\#]+#i', $url);
    }
}
