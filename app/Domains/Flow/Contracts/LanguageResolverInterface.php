<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Models\FlowSession;

interface LanguageResolverInterface
{
    /**
     * The session's own language first, then the contact's, then the tenant's fallback. A contactless session
     * ({@code $contact} null) skips the contact.
     */
    public function resolve(?Contact $contact, FlowSession $session): string;
}
