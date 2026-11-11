<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Conversation\Contracts\OperatorRequestsInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\StoppedTenantAction;
use App\Domains\Tenancy\Queue\TenantAccessGatedJob;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Ends the wait of a `request_operator` node nobody answered: the pending request goes back to the bot as timed out.
 *
 * Conditional on the request still pending, so an operator who accepted first (or a contact who cancelled) wins and
 * this job does nothing. The rest — the contact's button, the waiting session — follows from the request's state
 * change ({@see ContinueAfterOperatorRequestJob}).
 */
final class ExpireOperatorRequestJob implements ShouldQueue, TenantAccessGatedJob
{
    use Dispatchable;
    use Queueable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $requestId,
    ) {
        $this->onQueue('flow.execution');
    }

    public function accessModeTenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RespectsTenantAccessMode(StoppedTenantAction::Postpone)];
    }

    public function handle(TenantRepositoryInterface $tenants, TenantSwitcher $switcher): void
    {
        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        $switcher->runForTenant($tenant, function (): void {
            app(OperatorRequestsInterface::class)->expire($this->requestId);
        });
    }
}
