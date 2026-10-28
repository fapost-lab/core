<?php

declare(strict_types=1);

namespace App\Domains\Staff\Listeners;

use App\Domains\Staff\Jobs\SendWorkspaceDeletionNoticeJob;
use App\Domains\Tenancy\Events\WorkspaceDeletionCancelled;
use App\Domains\Tenancy\Events\WorkspaceDeletionScheduled;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Starts the mail to the tenant's admins about a workspace deletion that was scheduled or cancelled. Queues
 * {@see SendWorkspaceDeletionNoticeJob} and does nothing else, so the request that caused it stays fast.
 */
final readonly class NotifyAdminsOfWorkspaceDeletion
{
    public function __construct(private Dispatcher $bus)
    {
    }

    public function handle(WorkspaceDeletionScheduled|WorkspaceDeletionCancelled $event): void
    {
        $this->bus->dispatch(SendWorkspaceDeletionNoticeJob::for($event));
    }
}
