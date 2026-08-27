<?php

declare(strict_types=1);

namespace App\Domains\Staff\Notifications\Notifiers;

use App\Domains\Staff\Enums\StaffNotifyChannel;
use App\Domains\Staff\Mail\StaffNotificationMail;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Notifications\StaffNotifierInterface;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers staff notifications by email (D3).
 *
 * Available whenever the user has an email address (always true for staff in the
 * current schema, but guarded for forward-compatibility). Uses the Mail facade
 * — consistent with the rest of the staff mail pipeline (see
 * {@see \App\Domains\Staff\Jobs\SendActivationEmailJob}).
 */
final class EmailStaffNotifier implements StaffNotifierInterface
{
    public function channel(): string
    {
        return StaffNotifyChannel::Email->value;
    }

    public function isAvailableFor(User $user): bool
    {
        return '' !== mb_trim($user->email);
    }

    public function send(User $user, string $message): void
    {
        Mail::to($user->email)->send(
            new StaffNotificationMail($user->name, $message),
        );
    }
}
