<?php

declare(strict_types=1);

namespace App\Domains\Staff\Listeners;

use App\Domains\Staff\Services\SupportAccessEntryService;
use App\Domains\Staff\Support\SupportAccessSession;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Session\Session;

/**
 * Records that the operator left, however the support session ended: the banner's link, the Filament
 * sign-out, or the hour running out.
 */
final readonly class CloseSupportAccessEntry
{
    public function __construct(
        private Session $session,
        private SupportAccessEntryService $entries,
    ) {
    }

    public function handle(Logout $event): void
    {
        $support = new SupportAccessSession($this->session);
        $current = $support->current();

        if (null === $current) {
            return;
        }

        $this->entries->leave($current['entry_id']);
        $support->forget();
    }
}
