<?php

declare(strict_types=1);

namespace App\Domains\Staff\Notifications\Notifiers;

use App\Domains\Staff\Enums\StaffNotifyChannel;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Notifications\StaffNotifierInterface;
use Filament\Notifications\Notification;

/**
 * Delivers staff notifications as Filament in-app (database) notifications.
 *
 * This is the Core baseline transport (D1): zero external dependencies, visible
 * in the admin panel's notification inbox. Always available.
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

    public function send(User $user, string $message): void
    {
        Notification::make()
            ->title($message)
            ->info()
            ->sendToDatabase($user);
    }
}
