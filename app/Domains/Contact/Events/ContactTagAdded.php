<?php

declare(strict_types=1);

namespace App\Domains\Contact\Events;

/**
 * A tag was attached to a contact that did not have it.
 *
 * Raised by {@see \App\Domains\Contact\Repositories\ContactTagRepository} only when a row was really inserted, never
 * for a tag the contact already had. Nothing here references Flow: the Flow domain listens and turns it into the
 * `core.contact.tag_added` event trigger. Dispatched synchronously.
 */
final readonly class ContactTagAdded
{
    /**
     * @param  string|null  $taggedBy  the flow session or staff user that tagged the contact, when known
     */
    public function __construct(
        public string $tenantId,
        public string $contactId,
        public string $tag,
        public ?string $taggedBy = null,
    ) {
    }
}
