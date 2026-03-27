<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Jobs\SendActivationEmailJob;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
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
                $plain = $this->activationTokenService->issue($user);
                SendActivationEmailJob::dispatch(
                    $this->tenantContext->get()->getId(),
                    $user->getKey(),
                    $plain,
                );
            },
            300,
        );

        if ( ! $executed) {
            throw ValidationException::withMessages([
                'email' => __('Please wait :minutes minutes before requesting another activation email.', ['minutes' => 5]),
            ]);
        }
    }
}
