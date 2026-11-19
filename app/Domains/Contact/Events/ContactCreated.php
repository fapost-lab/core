<?php

declare(strict_types=1);

namespace App\Domains\Contact\Events;

/**
 * A contact was created: the first time the tenant saw this (platform, external id).
 *
 * Raised by {@see \App\Domains\Contact\Services\ContactService::findOrCreate()} only when the row was really inserted,
 * never when an existing contact was found. Contact does not depend on Flow: the Flow domain listens and turns it into
 * the `core.contact.created` event trigger. Dispatched synchronously, inside whatever created the contact.
 */
final readonly class ContactCreated
{
    public function __construct(
        public string $tenantId,
        public string $contactId,
        public string $platform,
        public string $language,
    ) {
    }
}
