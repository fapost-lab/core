<?php

declare(strict_types=1);

namespace App\Domains\Flow\Commands;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;

/**
 * Carries out a {@see ResolvedCommand} matched at routing pipeline Step 1.
 * Extracted as an interface so the router can be unit-tested in isolation.
 */
interface GlobalCommandExecutorInterface
{
    /**
     * @param  Assistant|null  $assistant    the assistant itself, for its busy message when a Solution is not ready
     * @param  string|null     $channelType  the type of the channel the command came through
     * @param  string|null     $updateId     the provider's id of the incoming update, the same on a retry
     */
    public function execute(
        ResolvedCommand $command,
        Contact $contact,
        string $assistantId,
        ?Assistant $assistant = null,
        ?string $channelType = null,
        ?string $updateId = null,
    ): void;
}
