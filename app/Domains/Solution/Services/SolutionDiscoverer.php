<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\DTO\ConnectorDiscovery;
use App\Domains\Solution\DTO\ExtensionDiscovery;
use App\Domains\Solution\DTO\InstalledConnector;
use App\Domains\Solution\DTO\InstalledPackage;
use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\DTO\SolutionDiscovery;
use App\Domains\Solution\DTO\SolutionViolation;
use Fapost\Foundation\Connector\Manifest\ConnectorManifestParser;
use Fapost\Foundation\Solution\Manifest\ManifestSchema;

/**
 * Scans the installed packages and validates their manifests, Solutions and connectors alike. Always reads the files;
 * the cache is {@see SolutionCache}'s business.
 *
 * Solutions and connectors share one id space: when one id is declared by a Solution and by a connector, both are
 * invalid.
 */
final class SolutionDiscoverer
{
    private readonly ConnectorManifestValidator $connectorValidator;

    public function __construct(
        private readonly InstalledPackagesReader $reader,
        private readonly SolutionManifestValidator $validator,
        ?ConnectorManifestValidator $connectorValidator = null,
    ) {
        $this->connectorValidator = $connectorValidator ?? new ConnectorManifestValidator(new ConnectorManifestParser());
    }

    public function discover(): SolutionDiscovery
    {
        return $this->discoverAll()->solutions;
    }

    public function discoverAll(): ExtensionDiscovery
    {
        $candidates = $this->reader->candidates();
        $solutions  = $this->validator->validate($candidates, $this->reader->installedJsonPath());
        $connectors = $this->connectorValidator->validate($candidates);

        // Ids are claimed by every package of a kind that names one, valid or not: a connector stays out when a broken
        // Solution claims its id too, so the outcome does not depend on which of them happens to be valid.
        $solutionClaims  = $this->claims($candidates, ManifestSchema::PACKAGE_TYPE);
        $connectorClaims = $this->claims($candidates, ManifestSchema::CONNECTOR_PACKAGE_TYPE);
        $shared          = array_keys(array_intersect_key($solutionClaims, $connectorClaims));

        if ([] === $shared) {
            return new ExtensionDiscovery($solutions, $connectors);
        }

        $violations = [];

        foreach ($shared as $id) {
            $id    = (string) $id;
            $names = [...$solutionClaims[$id], ...$connectorClaims[$id]];
            sort($names);

            $violations[] = new SolutionViolation(
                implode(', ', $names),
                'extra.fapost.id',
                sprintf('"%s" is declared by %s', $id, 2 === count($names) ? 'both packages' : 'all ' . count($names) . ' packages'),
            );
        }

        $solutionViolations = [...$solutions->violations, ...$violations];
        usort($solutionViolations, static fn (SolutionViolation $a, SolutionViolation $b): int => $a->line() <=> $b->line());

        return new ExtensionDiscovery(
            new SolutionDiscovery(
                array_values(array_filter($solutions->valid, static fn (InstalledSolution $solution): bool => !in_array($solution->id, $shared, true))),
                $solutionViolations,
                $solutions->warnings,
                $solutions->installedJsonPath,
                $solutions->watchedFiles,
            ),
            new ConnectorDiscovery(
                array_values(array_filter($connectors->valid, static fn (InstalledConnector $connector): bool => !in_array($connector->id, $shared, true))),
                $connectors->violations,
                $connectors->watchedFiles,
            ),
        );
    }

    /**
     * The ids the packages of one type name in `extra.fapost.id`, with the packages naming each.
     *
     * @param  list<InstalledPackage>  $candidates
     * @return array<string, list<string>>
     */
    private function claims(array $candidates, string $type): array
    {
        $claims = [];

        foreach ($candidates as $package) {
            $extra = $package->composer['extra'] ?? null;
            $block = is_array($extra) ? ($extra['fapost'] ?? null) : null;
            $id    = is_array($block) ? ($block['id'] ?? null) : null;

            if ($type === $package->type && is_string($id) && '' !== $id) {
                $claims[$id][] = $package->name;
            }
        }

        return $claims;
    }
}
