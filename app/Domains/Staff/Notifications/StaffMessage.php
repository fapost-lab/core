<?php

declare(strict_types=1);

namespace App\Domains\Staff\Notifications;

/**
 * What a {@see StaffNotifierInterface} delivers: the already-rendered text, and optionally a link to what it is about.
 *
 * The link is a path on the tenant's own host (`/assistant/…`) or an `http(s)` address. A path is kept as it is in the
 * in-app notification, so the bell opens it on whatever host the user is signed in on; a transport that leaves the
 * console (email) makes it absolute on the tenant's host. Anything else (`javascript:`, `//host`, a path with
 * whitespace or a backslash) is dropped here, so no transport ever renders it as a link.
 */
final readonly class StaffMessage
{
    public ?string $url;

    public function __construct(
        public string $text,
        ?string $url = null,
        public ?string $actionLabel = null,
    ) {
        $this->url = null !== $url && self::isSafeUrl($url) ? $url : null;
    }

    /**
     * A path on this host (`/…`, never `//…`) or an `http(s)` address on any host. Whitespace, control characters and
     * backslashes are refused anywhere, since browsers drop or reinterpret them (`/<tab>/evil.test` and `/\evil.test`
     * both open another host).
     */
    public static function isSafeUrl(string $url): bool
    {
        if ('' === $url || 1 === preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }

        return 1 === preg_match('#^https?://[^/?\#]+#i', $url);
    }

    public function hasLink(): bool
    {
        return null !== $this->url;
    }
}
