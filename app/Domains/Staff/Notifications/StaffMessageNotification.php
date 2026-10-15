<?php

declare(strict_types=1);

namespace App\Domains\Staff\Notifications;

use App\Domains\Staff\Models\User;
use Illuminate\Notifications\Notification;

/**
 * A message from a flow's staff-notify node, stored for the staff member as an in-app (database) notification.
 *
 * The stored `data` has the shape Filament's database notifications had (`Notification::make()->title()->info()`),
 * key for key, so the rows written before and after Filament left read the same way when an inbox shows them.
 *
 * Sent with `notifyNow` from {@see Notifiers\InAppStaffNotifier}, inside the tenant switch of the notify job: a queued
 * notification would restore the user outside the tenant.
 */
final class StaffMessageNotification extends Notification
{
    public function __construct(
        private readonly string $message,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{actions: list<never>, body: null, color: null, duration: string, icon: string, iconColor: string, status: string, title: string, view: null, viewData: list<never>, format: string}
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'actions'   => [],
            'body'      => null,
            'color'     => null,
            'duration'  => 'persistent',
            'icon'      => 'heroicon-o-information-circle',
            'iconColor' => 'info',
            'status'    => 'info',
            'title'     => $this->message,
            'view'      => null,
            'viewData'  => [],
            'format'    => 'filament',
        ];
    }
}
