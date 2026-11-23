<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\DTOs\ContactFlowStart;

/**
 * Queues the start a `start_flow` node asked for, once the step that asked has committed. The receiving job re-checks
 * everything under the contact's lock and starts nothing twice for one start key.
 */
interface ContactFlowStartDispatcherInterface
{
    public function dispatch(ContactFlowStart $start): void;
}
