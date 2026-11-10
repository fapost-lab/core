<?php

declare(strict_types=1);

namespace App\Domains\Staff\Live;

use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Infrastructure\Broadcasting\BroadcasterStatus;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Tells a staff user's open consoles that their notifications changed, as {@see StaffNotificationsChanged} on their own
 * {@see StaffNotificationsChannel}.
 *
 * Works like {@see \App\Domains\Conversation\Live\ConversationActivityNotifier}: with no delivering broadcaster, or no
 * tenant in context, it returns at once; otherwise it waits for the write's transaction to commit and reports any
 * failure instead of throwing it into whatever sent or read the notification. It is not throttled: a notification is a
 * rare event for one user, and the bell only reads a count.
 *
 * Built per use (it reads the scoped tenant context), never a singleton.
 */
final readonly class StaffNotificationsAnnouncer
{
    public function __construct(
        private BroadcasterStatus $broadcaster,
        private TenantContextInterface $tenants,
        private Dispatcher $events,
        private ExceptionHandler $exceptions,
    ) {
    }

    public function changed(User $user): void
    {
        try {
            if (! $this->broadcaster->enabled() || ! $this->tenants->isResolved()) {
                return;
            }

            $tenantId = $this->tenants->get()->getId();
            $userId   = (string) $user->getKey();

            if ('' === $tenantId || '' === $userId) {
                return;
            }

            // Runs at once when no transaction is open.
            $user->getConnection()->afterCommit(function () use ($tenantId, $userId): void {
                try {
                    $this->events->dispatch(new StaffNotificationsChanged($tenantId, $userId));
                } catch (Throwable $exception) {
                    $this->exceptions->report($exception);
                }
            });
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }
}
