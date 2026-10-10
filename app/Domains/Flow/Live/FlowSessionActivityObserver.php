<?php

declare(strict_types=1);

namespace App\Domains\Flow\Live;

use App\Domains\Flow\Models\FlowSession;

/**
 * Turns every saved change of a {@see FlowSession} into a live announcement. {@see FlowSession::saveWithOptimisticLock()}
 * writes with a query, not `save()`, and raises the `updated` model event itself so the engine's writes count too.
 */
final readonly class FlowSessionActivityObserver
{
    public function __construct(
        private FlowActivityNotifier $notifier,
    ) {
    }

    public function created(FlowSession $session): void
    {
        $this->notifier->touched($session);
    }

    public function updated(FlowSession $session): void
    {
        $this->notifier->touched($session);
    }
}
