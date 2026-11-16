<?php

declare(strict_types=1);

namespace App\Infrastructure\Solution;

use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Jobs\Solution\PrepareSolutionSchemaJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;

/**
 * Whether a Solution's tables in the current tenant's schema are current, and if not, has them prepared on the queue.
 * Never runs DDL itself: it is asked on flow steps and console requests. The job is unique per tenant and Solution, so
 * asking again while it waits queues nothing more. The container is the only thing held: {@see ActiveSolutions} and
 * the tenant context are scoped.
 */
final readonly class SolutionPreparation
{
    public function __construct(
        private Container $container,
    ) {
    }

    public function isReadyOrPrepare(string $solutionId): bool
    {
        $context = $this->container->make(TenantContextInterface::class);

        if (!$context->isResolved()) {
            return false;
        }

        if ($this->container->make(ActiveSolutions::class)->isReady($solutionId)) {
            return true;
        }

        $this->container->make(Dispatcher::class)->dispatch(new PrepareSolutionSchemaJob($context->get()->getId(), $solutionId));

        return false;
    }
}
