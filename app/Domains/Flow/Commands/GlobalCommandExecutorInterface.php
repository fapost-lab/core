<?php

declare(strict_types=1);

namespace App\Domains\Flow\Commands;

use App\Domains\Contact\Models\Contact;

/**
 * Carries out a {@see ResolvedCommand} matched at routing pipeline Step 1.
 * Extracted as an interface so the router can be unit-tested in isolation.
 */
interface GlobalCommandExecutorInterface
{
    public function execute(ResolvedCommand $command, Contact $contact, string $assistantId): void;
}
