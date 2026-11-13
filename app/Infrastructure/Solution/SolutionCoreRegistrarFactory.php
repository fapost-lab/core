<?php

declare(strict_types=1);

namespace App\Infrastructure\Solution;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Contracts\NodeHandlerFactoryInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\State\StateNamespaceRegistry;
use App\Domains\Solution\Contracts\SolutionRegistrarFactoryInterface;
use App\Domains\Solution\DTO\InstalledSolution;
use Fapost\Foundation\Contracts\CoreRegistrarInterface;

final readonly class SolutionCoreRegistrarFactory implements SolutionRegistrarFactoryInterface
{
    public function __construct(
        private ActionHandlerRegistry $actions,
        private NodeHandlerRegistryInterface $nodes,
        private NodeHandlerFactoryInterface $nodeFactory,
        private StateNamespaceRegistry $namespaces,
    ) {
    }

    public function forSolution(InstalledSolution $solution): CoreRegistrarInterface
    {
        // The manifest's `state: true` gives the Solution the namespace named by its id. Done here, once per
        // Solution and process, so the namespace is known before any of its nodes register.
        if ($solution->state) {
            $this->namespaces->registerSolutionNamespace($solution->id);
        }

        return new SolutionCoreRegistrar($solution, $this->actions, $this->nodes, $this->nodeFactory);
    }
}
