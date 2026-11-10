<?php

declare(strict_types=1);

namespace App\Domains\Staff\Notifications\Notifiers;

use App\Domains\Staff\Enums\StaffNotifyChannel;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Notifications\StaffMessage;
use App\Domains\Staff\Notifications\StaffMessageNotification;
use App\Domains\Staff\Notifications\StaffNotifierInterface;

/**
 * Delivers staff notifications as in-app (database) notifications.
 *
 * This is the Core baseline transport (D1): zero external dependencies, always available. The rows are what the
 * console's bell lists; a message's link becomes the notification's action.
 */
final class InAppStaffNotifier implements StaffNotifierInterface
{
    public function channel(): string
    {
        return StaffNotifyChannel::InApp->value;
    }

    public function isAvailableFor(User $user): bool
    {
        return true;
    }

    public function send(User $user, StaffMessage $message): void
    {
        $user->notifyNow(new StaffMessageNotification($message));
    }
}
