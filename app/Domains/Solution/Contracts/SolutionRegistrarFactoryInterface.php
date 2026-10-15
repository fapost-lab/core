<?php

declare(strict_types=1);

namespace App\Domains\Solution\Contracts;

use App\Domains\Solution\DTO\InstalledSolution;
use Fapost\Foundation\Contracts\CoreRegistrarInterface;

/**
 * Gives one Solution the registrar it registers its extensions through.
 *
 * The registrar is made per Solution so that what it registers can be checked against that Solution's
 * manifest. The implementation lives outside this domain because it writes into the registries of the
 * domains a Solution extends, and this domain sits below all of them.
 */
interface SolutionRegistrarFactoryInterface
{
    public function forSolution(InstalledSolution $solution): CoreRegistrarInterface;
}
