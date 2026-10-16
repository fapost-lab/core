<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use Fapost\Foundation\Solution\Contracts\SolutionStorefrontInterface;
use Fapost\Foundation\Solution\DTO\SolutionConnectResult;

/**
 * Default {@see SolutionStorefrontInterface}: nothing is offered and nothing can be connected. An installation without
 * an operator package that sells Solutions shows no offers.
 */
final class NoSolutionStorefront implements SolutionStorefrontInterface
{
    public function offersFor(string $tenantId): array
    {
        return [];
    }

    public function connect(string $tenantId, string $solutionId, string $requestedBy): SolutionConnectResult
    {
        return SolutionConnectResult::refused();
    }
}
