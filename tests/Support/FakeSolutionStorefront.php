<?php

declare(strict_types=1);

namespace Tests\Support;

use Fapost\Foundation\Solution\Contracts\SolutionStorefrontInterface;
use Fapost\Foundation\Solution\DTO\SolutionConnectResult;
use Fapost\Foundation\Solution\DTO\SolutionOffer;
use RuntimeException;

/**
 * An operator's storefront for tests: the offers it lists, the result of a connect (or a callback that decides it),
 * an outage, and a record of the connects it was asked for.
 */
final class FakeSolutionStorefront implements SolutionStorefrontInterface
{
    /** @var list<SolutionOffer> */
    public array $offers = [];

    public ?SolutionConnectResult $result = null;

    /** @var (callable(string, string): SolutionConnectResult)|null */
    public $onConnect = null;

    public bool $failing = false;

    /** @var list<array{tenant: string, solution: string, by: string}> */
    public array $connects = [];

    public function offersFor(string $tenantId): array
    {
        if ($this->failing) {
            throw new RuntimeException('The storefront is down.');
        }

        return $this->offers;
    }

    public function connect(string $tenantId, string $solutionId, string $requestedBy): SolutionConnectResult
    {
        if ($this->failing) {
            throw new RuntimeException('The storefront is down.');
        }

        $this->connects[] = ['tenant' => $tenantId, 'solution' => $solutionId, 'by' => $requestedBy];

        if (null !== $this->onConnect) {
            return ($this->onConnect)($tenantId, $solutionId);
        }

        return $this->result ?? SolutionConnectResult::requested();
    }
}
