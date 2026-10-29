<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Jobs;

use App\Domains\Tenancy\Contracts\TenantDeletionRequestRepositoryInterface;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Tenancy\Contracts\TenantDecommissionerInterface;
use Fapost\Foundation\Tenancy\Enums\DecommissionFailure;
use Fapost\Foundation\Tenancy\Enums\DecommissionReason;
use Fapost\Foundation\Tenancy\Exceptions\TenantDecommissionFailedException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes a tenant completely, outside the request that asked for it: the removal calls the providers, deletes
 * files and drops a schema, which is too slow for an HTTP request.
 *
 * Not gated by the tenant access mode: the operator's retention deletes tenants that are stopped by definition, and
 * the owner's request is carried out for a stopped tenant too. The removal is safe to repeat, so a failed run is
 * retried and finishes what is left; a Pending tenant is refused for good and the job fails at once.
 *
 * Unique per tenant: a second job for the same tenant (the scheduler picking a request up again before the first run
 * reached it) is dropped while one is queued or running. It runs on a queue of its own, on a connection whose
 * `retry_after` exceeds the job's timeout, so a long run is never reserved a second time by another worker.
 *
 * An owner's request carries the id of its record: before the removal the job re-checks that the request was not
 * cancelled meanwhile, and marks it started (after which it can no longer be cancelled). A retry of a started request
 * goes on. The operator's retention passes no request.
 *
 * The tenant no longer exists once it has run, so nothing here reads tenant data.
 */
final class DecommissionTenantJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string CONNECTION = 'redis-long';

    public const string QUEUE = 'tenancy.decommission';

    /** Seconds one run may take; the connection's `retry_after` is larger. */
    public const int TIMEOUT_SECONDS = 1500;

    public int $tries = 5;

    public int $timeout = self::TIMEOUT_SECONDS;

    /** Seconds the uniqueness lock outlives a job that died without releasing it. */
    public int $uniqueFor = 7200;

    public function __construct(
        public readonly string $tenantId,
        public readonly DecommissionReason $reason,
        public readonly ?string $requestId = null,
    ) {
        $this->onConnection(self::CONNECTION);
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(TenantDecommissionerInterface $decommissioner, TenantDeletionRequestRepositoryInterface $requests): void
    {
        if (null !== $this->requestId && ! $requests->claim($this->requestId, CarbonImmutable::now('UTC'))) {
            // Cancelled (or gone) since the scheduler queued it: the workspace stays.
            return;
        }

        try {
            $decommissioner->decommission($this->tenantId, $this->reason);
        } catch (TenantDecommissionFailedException $exception) {
            if (DecommissionFailure::Pending === $exception->reason) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }
}
