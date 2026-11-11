<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Jobs\SendActivationEmailJob;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Re-issues activation token and queues email with per-user rate limiting.
 */
final readonly class ResendActivationService
{
    public function __construct(
        private ActivationTokenService $activationTokenService,
        private TenantContextInterface $tenantContext,
        private AuditTrailInterface $audit,
    ) {
    }

    public function resend(User $user): void
    {
        if (UserStatus::Pending !== $user->status) {
            throw ValidationException::withMessages([
                'status' => __('Only pending users can receive a new activation link.'),
            ]);
        }

        $executed = RateLimiter::attempt(
            'resend-activation:' . $user->getKey(),
            1,
            function () use ($user): void {
                // The new token and its audit entry commit together; the mail is queued only after both.
                $plain = DB::transaction(function () use ($user): string {
                    $plain = $this->activationTokenService->issue($user);

                    // The new link is the only thing recorded; the token never is.
                    $this->audit->record(new AuditRecord(
                        action: AuditAction::UserActivationResent,
                        subject: $user,
                        subjectLabel: $user->name,
                    ));

                    return $plain;
                });

                SendActivationEmailJob::dispatch(
                    $this->tenantContext->get()->getId(),
                    $user->getKey(),
                    $plain,
                );
            },
            300,
        );

        if (! $executed) {
            throw ValidationException::withMessages([
                'email' => __(
                    'Please wait :minutes minutes before requesting another activation email.',
                    ['minutes' => 5]
                ),
            ]);
        }
    }
}
