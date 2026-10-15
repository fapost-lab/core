<?php

declare(strict_types=1);

namespace App\Infrastructure\Solution;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Solution\Contracts\SolutionRegistrarFactoryInterface;
use App\Domains\Solution\DTO\InstalledSolution;
use Fapost\Foundation\Contracts\CoreRegistrarInterface;

final readonly class SolutionCoreRegistrarFactory implements SolutionRegistrarFactoryInterface
{
    public function __construct(
        private ActionHandlerRegistry $actions,
    ) {
    }

    public function forSolution(InstalledSolution $solution): CoreRegistrarInterface
    {
        return new SolutionCoreRegistrar($solution, $this->actions);
    }
}
