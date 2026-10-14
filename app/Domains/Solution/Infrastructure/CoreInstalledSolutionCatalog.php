<?php

declare(strict_types=1);

namespace App\Domains\Solution\Infrastructure;

use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\Services\InstalledSolutions;
use Fapost\Foundation\Solution\Contracts\InstalledSolutionCatalogInterface;
use Fapost\Foundation\Solution\DTO\InstalledSolutionInfo;

/**
 * Core's answer to the Foundation catalog contract: the installed Solutions, as the operator package sees them.
 */
final class CoreInstalledSolutionCatalog implements InstalledSolutionCatalogInterface
{
    public function __construct(private readonly InstalledSolutions $solutions)
    {
    }

    public function all(): array
    {
        return array_map($this->info(...), $this->solutions->all());
    }

    public function find(string $id): ?InstalledSolutionInfo
    {
        $solution = $this->solutions->find($id);

        return null === $solution ? null : $this->info($solution);
    }

    public function has(string $id): bool
    {
        return $this->solutions->has($id);
    }

    private function info(InstalledSolution $solution): InstalledSolutionInfo
    {
        return new InstalledSolutionInfo($solution->id, $solution->name, $solution->description, $solution->version);
    }
}
