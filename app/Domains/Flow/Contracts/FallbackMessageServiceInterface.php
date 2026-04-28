<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Contact\Models\Contact;

/**
 * Sends a plain-text fallback message to a contact without an active flow session.
 * Used when no trigger matches and no default flow is configured for the assistant.
 */
interface FallbackMessageServiceInterface
{
    public function send(Contact $contact, string $assistantId, string $text): void;
}
