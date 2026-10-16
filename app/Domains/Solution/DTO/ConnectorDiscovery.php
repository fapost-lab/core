<?php

declare(strict_types=1);

namespace App\Domains\Solution\DTO;

/**
 * What a scan of the installed packages found among connectors: the ones Core may boot and everything wrong with the rest.
 */
final readonly class ConnectorDiscovery
{
    /**
     * @param  list<InstalledConnector>  $valid  ordered by id; a package with a violation, and every package of an id or channel collision, are absent
     * @param  list<SolutionViolation>  $violations
     * @param  list<string>  $watchedFiles  composer.json of every connector candidate, valid or not; a cache goes stale when one changes
     */
    public function __construct(
        public array $valid,
        public array $violations,
        public array $watchedFiles = [],
    ) {
    }

    public function isValid(): bool
    {
        return [] === $this->violations;
    }
}
