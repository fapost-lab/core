<?php

declare(strict_types=1);

namespace App\Domains\Staff\Jobs;

use App\Domains\Staff\Mail\ActivationMail;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Sends activation email inside the correct tenant schema (queue workers have no HTTP tenant middleware).
 */
final class SendActivationEmailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $tenantId,
        public int|string $userId,
        public string $plainToken,
    ) {
    }

    public function handle(TenantRepositoryInterface $tenantRepository, TenantSwitcher $tenantSwitcher): void
    {
        $tenant = $tenantRepository->getById($this->tenantId);

        $tenantSwitcher->runForTenant($tenant, function (): void {
            $user = User::query()->findOrFail($this->userId);

            Mail::to($user->email)->send(new ActivationMail($user, $this->plainToken));
        });
    }
}
