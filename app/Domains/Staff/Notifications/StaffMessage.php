<?php

declare(strict_types=1);

namespace App\Domains\Staff\Notifications;

/**
 * What a {@see StaffNotifierInterface} delivers: the already-rendered text, and optionally a link to what it is about.
 *
 * The link is a path on the tenant's own host (`/assistant/…`) or an `http(s)` address. A path is kept as it is in the
 * in-app notification, so the bell opens it on whatever host the user is signed in on; a transport that leaves the
 * console (email) makes it absolute on the tenant's host.
 */
final readonly class StaffMessage
{
    public function __construct(
        public string $text,
        public ?string $url = null,
        public ?string $actionLabel = null,
    ) {
    }

    public function hasLink(): bool
    {
        return null !== $this->url && '' !== $this->url;
    }
}
