<?php

declare(strict_types=1);

namespace App\Domains\Staff\Listeners;

use App\Domains\Staff\Live\StaffNotificationsAnnouncer;
use App\Domains\Staff\Models\User;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Announces every in-app notification a staff user receives, whatever sent it (a flow's notify step, a limit notice,
 * a late onboarding step, a conversation), so the bell hears of it in one place rather than at every call site.
 * Only the `database` channel counts: that is what the bell lists.
 */
final readonly class AnnounceStaffNotification
{
    public function __construct(
        private StaffNotificationsAnnouncer $announcer,
    ) {
    }

    public function handle(NotificationSent $event): void
    {
        if ('database' === $event->channel && $event->notifiable instanceof User) {
            $this->announcer->changed($event->notifiable);
        }
    }
}
