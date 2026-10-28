<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Domains\Tenancy\Contracts\TenantDeletionRequestRepositoryInterface;
use App\Domains\Tenancy\Jobs\DecommissionTenantJob;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Tenancy\Enums\DecommissionReason;
use Illuminate\Console\Command;

/**
 * Starts the workspace deletions whose 24 hours are up: queues {@see DecommissionTenantJob} for every active request
 * that is due. The job re-checks the request itself, so a cancel that lands after this ran still wins; and it is unique
 * per tenant, so a request picked up again before the job reached it queues nothing twice. Landlord-wide, run every
 * minute by the scheduler.
 */
final class TenantsStartDeletionsCommand extends Command
{
    /** @var string */
    protected $signature = 'tenants:start-deletions';

    /** @var string */
    protected $description = 'Queues the removal of workspaces whose scheduled deletion is due';

    public function handle(TenantDeletionRequestRepositoryInterface $requests): int
    {
        $queued = 0;

        foreach ($requests->due(CarbonImmutable::now('UTC')) as $request) {
            dispatch(new DecommissionTenantJob($request->tenant_id, DecommissionReason::OwnerRequest, $request->getKey()));
            $queued++;
        }

        $this->info(sprintf('Queued %d workspace removal(s).', $queued));

        return self::SUCCESS;
    }
}
