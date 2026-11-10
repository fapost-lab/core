<?php

declare(strict_types=1);

namespace App\Domains\Staff\Notifications\Notifiers;

use App\Domains\Staff\Enums\StaffNotifyChannel;
use App\Domains\Staff\Mail\StaffNotificationMail;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Notifications\StaffMessage;
use App\Domains\Staff\Notifications\StaffNotifierInterface;
use Closure;
use Illuminate\Support\Facades\Mail;

/**
 * Delivers staff notifications by email (D3).
 *
 * Available whenever the user has an email address (always true for staff in the
 * current schema, but guarded for forward-compatibility). Uses the Mail facade
 * — consistent with the rest of the staff mail pipeline (see
 * {@see \App\Domains\Staff\Jobs\SendActivationEmailJob}).
 *
 * A message's link becomes a button. A mail is read outside the console, so a path is made absolute on the current
 * tenant's host by `$absoluteUrl`, which the provider supplies and which reads the tenant at the time of sending: the
 * registry holding this notifier lives for the whole worker, so it must not capture a tenant.
 */
final readonly class EmailStaffNotifier implements StaffNotifierInterface
{
    /**
     * @param  Closure(string): string  $absoluteUrl  a path on the tenant's host to its absolute address
     */
    public function __construct(
        private Closure $absoluteUrl,
    ) {
    }

    public function channel(): string
    {
        return StaffNotifyChannel::Email->value;
    }

    public function isAvailableFor(User $user): bool
    {
        return '' !== mb_trim($user->email);
    }

    public function send(User $user, StaffMessage $message): void
    {
        Mail::to($user->email)->send(
            new StaffNotificationMail($user->name, $message->text, $this->url($message), $message->actionLabel),
        );
    }

    private function url(StaffMessage $message): ?string
    {
        if (! $message->hasLink()) {
            return null;
        }

        $url = (string) $message->url;

        return str_starts_with($url, '/') && ! str_starts_with($url, '//') ? ($this->absoluteUrl)($url) : $url;
    }
}
