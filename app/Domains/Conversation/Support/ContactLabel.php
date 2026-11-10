<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Support;

use App\Domains\Conversation\Models\Conversation;

/**
 * How the inbox names the contact of a thread: the name in the contact's meta, else `@username`, else the id the
 * channel knows them by (the thread's id when the contact is gone).
 */
final class ContactLabel
{
    public static function of(Conversation $conversation): string
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
}
