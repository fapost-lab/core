<?php

declare(strict_types=1);

namespace App\Domains\Flow\Routing;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;

/**
 * Side-effect surface invoked by {@see MessageRouter} when a message has
 * to be dropped. Extracted to keep the router unit-testable.
 */
interface DropPolicyInterface
{
    public function applyBusy(Contact $contact, Assistant $assistant): void;

    public function applySilent(Contact $contact, Assistant $assistant): void;
}
