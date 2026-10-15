<?php

declare(strict_types=1);

namespace App\Infrastructure\Solution;

use App\Domains\Flow\Contracts\ActionAvailabilityInterface;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Answers from the Solutions the current tenant has activated. The container is the only thing held:
 * {@see ActiveSolutions} and the tenant context are scoped, so they are resolved on every question
 * and a deactivation reaches the next job of any worker.
 */
final readonly class ContainerActionAvailability implements ActionAvailabilityInterface
{
    public function __construct(
        private Container $container,
    ) {
    }

    public function isAvailable(?string $solutionId): bool
    {
        if (null === $solutionId) {
            return true;
        }

        // No tenant means nobody has activated anything.
        if (!$this->container->make(TenantContextInterface::class)->isResolved()) {
            return false;
        }

        return $this->container->make(ActiveSolutions::class)->isActive($solutionId);
    }
}
