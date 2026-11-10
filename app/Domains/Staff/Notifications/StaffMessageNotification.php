<?php

declare(strict_types=1);

namespace App\Domains\Staff\Notifications;

use App\Domains\Staff\Models\User;
use Illuminate\Notifications\Notification;

/**
 * A message from a flow's staff-notify node, stored for the staff member as an in-app (database) notification.
 *
 * The stored `data` has the shape Filament's database notifications had (`Notification::make()->title()->info()`),
 * key for key, so the rows written before and after Filament left read the same way when an inbox shows them. A message
 * with a link stores it as the one entry of Filament's `actions` (`name`, `label`, `url`), which the bell opens.
 *
 * Sent with `notifyNow` from {@see Notifiers\InAppStaffNotifier}, inside the tenant switch of the notify job: a queued
 * notification would restore the user outside the tenant.
 */
final class StaffMessageNotification extends Notification
{
    public function __construct(
        private readonly StaffMessage $message,
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
     * @return array{actions: list<array{name: string, label: string|null, url: string}>, body: null, color: null, duration: string, icon: string, iconColor: string, status: string, title: string, view: null, viewData: list<never>, format: string}
     */
    public function toDatabase(User $notifiable): array
    {
        return [
            'actions'   => $this->actions(),
            'body'      => null,
            'color'     => null,
            'duration'  => 'persistent',
            'icon'      => 'heroicon-o-information-circle',
            'iconColor' => 'info',
            'status'    => 'info',
            'title'     => $this->message->text,
            'view'      => null,
            'viewData'  => [],
            'format'    => 'filament',
        ];
    }

    /**
     * @return list<array{name: string, label: string|null, url: string}>
     */
    private function actions(): array
    {
        if (! $this->message->hasLink()) {
            return [];
        }

        return [['name' => 'open', 'label' => $this->message->actionLabel, 'url' => (string) $this->message->url]];
    }
}
