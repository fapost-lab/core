<?php

declare(strict_types=1);

namespace App\Domains\Solution\DTO;

/**
 * What a scan of the installed packages found: the Solutions Core may use and everything wrong with the rest.
 */
final readonly class SolutionDiscovery
{
    /**
     * @param  list<InstalledSolution>  $valid  ordered by id; a package with a violation, and both packages of an id collision, are absent
     * @param  list<SolutionViolation>  $violations
     * @param  list<string>  $warnings  things worth saying that do not stop an update
     * @param  string  $installedJsonPath  the installed.json that was read
     * @param  list<string>  $watchedFiles  composer.json of every candidate package, valid or not; a cache goes stale when one changes
     */
    public function __construct(
        public array $valid,
        public array $violations,
        public array $warnings,
        public string $installedJsonPath,
        public array $watchedFiles = [],
    ) {
    }

    public function isValid(): bool
    {
        return [] === $this->violations;
    }
}
