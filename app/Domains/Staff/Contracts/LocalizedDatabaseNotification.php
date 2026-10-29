<?php

declare(strict_types=1);

namespace App\Domains\Staff\Contracts;

/**
 * A database notification whose text is rendered again in the viewer's language when the bell lists it.
 *
 * A stored notification is written once, in the recipient's `locale`, which is often empty: the console's language
 * lives in the session and the cookie, not on the user. A notification that stores what it is about (a key, not only
 * prose) implements this, and {@see \App\Domains\Staff\Services\StaffNotificationInbox} asks it for the text in the
 * locale of the request. The stored prose stays as the fallback for every other reader.
 */
interface LocalizedDatabaseNotification
{
    /**
     * The text for one stored row, in the given locale; a key left out keeps the stored value.
     *
     * @param  array<array-key, mixed>  $data  the row's stored `data`
     * @return array{title?: string, body?: string, actionLabel?: string}
     */
    public static function localize(array $data, string $locale): array;
}
