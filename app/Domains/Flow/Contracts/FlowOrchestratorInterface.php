<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Contact\Models\Contact;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\Flow\DTO\ResolvedTrigger;

interface FlowOrchestratorInterface
{
    public function handle(
        Contact $contact,
        IncomingMessage $message,
        string $assistantId,
        ?ResolvedTrigger $trigger = null
    ): void;
}
