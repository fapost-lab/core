<?php

declare(strict_types=1);

namespace App\Domains\Contact\Events;

/**
 * A tag was taken off a contact that had it.
 *
 * Raised by {@see \App\Domains\Contact\Repositories\ContactTagRepository} only when a row was really deleted, never
 * for a tag the contact did not have. Contact does not depend on Flow: the Flow domain listens and turns it into the
 * `core.contact.tag_removed` event trigger. Dispatched synchronously.
 */
final readonly class ContactTagRemoved
{
    public function __construct(
        public string $tenantId,
        public string $contactId,
        public string $tag,
    ) {
    }
}
