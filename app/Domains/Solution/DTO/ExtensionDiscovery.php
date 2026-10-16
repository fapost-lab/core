<?php

declare(strict_types=1);

namespace App\Domains\Solution\DTO;

/**
 * Both kinds of extension package found in one scan, after the check that Solutions and connectors share one id space.
 */
final readonly class ExtensionDiscovery
{
    public function __construct(
        public SolutionDiscovery $solutions,
        public ConnectorDiscovery $connectors,
    ) {
    }

    public function isValid(): bool
    {
        return $this->solutions->isValid() && $this->connectors->isValid();
    }

    /**
     * @return list<SolutionViolation> Solution violations first, then connector ones
     */
    public function violations(): array
    {
        return [...$this->solutions->violations, ...$this->connectors->violations];
    }
}
