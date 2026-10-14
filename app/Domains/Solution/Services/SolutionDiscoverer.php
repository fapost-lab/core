<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\DTO\SolutionDiscovery;

/**
 * Scans the installed packages and validates their manifests. Always reads the files; the cache is
 * {@see SolutionCache}'s business.
 */
final class SolutionDiscoverer
{
    public function __construct(
        private readonly InstalledPackagesReader $reader,
        private readonly SolutionManifestValidator $validator,
    ) {
    }

    public function discover(): SolutionDiscovery
    {
        return $this->validator->validate($this->reader->candidates(), $this->reader->installedJsonPath());
    }
}
