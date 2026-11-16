<?php

declare(strict_types=1);

namespace App\Domains\Solution\Contracts;

/**
 * The Solutions present in the schema of the tenant the caller is switched into: those whose tenant migrations
 * have run here at least once (activated, or granted and prepared). The upgrade keeps exactly these current.
 */
interface SolutionSchemaStoreInterface
{
    /**
     * The digest of the migration names each Solution's tables here were last brought up to, by Solution id
     * ({@see \App\Domains\Solution\DTO\InstalledSolution::digestOf()}).
     *
     * @return array<string, string>
     */
    public function appliedDigests(): array;
}
