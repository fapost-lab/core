<?php

declare(strict_types=1);

namespace App\Infrastructure\Solution;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Contracts\NodeHandlerFactoryInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Solution\DTO\InstalledSolution;
use Closure;
use Fapost\Foundation\Action\ActionHandlerInterface;
use Fapost\Foundation\Contracts\CoreRegistrarInterface;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\Flow\Enums\NodeCategory;
use LogicException;

/**
 * The Core side of {@see CoreRegistrarInterface} for one Solution.
 *
 * A Solution registers action handlers and node handlers and nothing else for now. Every other call fails
 * at once with a message naming the Solution, because a quiet no-op would show up at a client, not on the
 * author's first run. An action must be declared in the Solution's manifest (`extra.fapost.actions`), a node
 * type in `extra.fapost.nodes`; that, together with the manifest rule that both start with the Solution id,
 * is what keeps one Solution out of another's (or Core's) namespace.
 */
final readonly class SolutionCoreRegistrar implements CoreRegistrarInterface
{
    public function __construct(
        private InstalledSolution $solution,
        private ActionHandlerRegistry $actions,
        private NodeHandlerRegistryInterface $nodes,
        private NodeHandlerFactoryInterface $nodeFactory,
    ) {
    }

    public function registerActionHandler(string $handlerClass): void
    {
        if (!is_a($handlerClass, ActionHandlerInterface::class, true)) {
            throw new LogicException(sprintf(
                '%s registers %s, which is not an action handler: it must implement %s.',
                $this->label(),
                $handlerClass,
                ActionHandlerInterface::class,
            ));
        }

        $id = $this->actions->idOf($handlerClass);

        if (!in_array($id, $this->solution->actions, true)) {
            throw new LogicException(sprintf(
                '%s registers action "%s" (%s), which extra.fapost.actions does not declare.',
                $this->label(),
                $id,
                $handlerClass,
            ));
        }

        $this->actions->register($handlerClass, $this->solution->id, $id);
    }

    public function registerNodeHandler(string $handlerClass): void
    {
        if (!is_a($handlerClass, NodeHandlerInterface::class, true)) {
            throw new LogicException(sprintf(
                '%s registers %s, which is not a node handler: it must implement %s.',
                $this->label(),
                $handlerClass,
                NodeHandlerInterface::class,
            ));
        }

        // The registry keeps the class; this throwaway instance only reads the handler's identity.
        $handler  = $this->nodeFactory->make($handlerClass);
        $type     = $handler->type();
        $category = $handler->category();

        if (!in_array($type, $this->solution->nodes, true)) {
            throw new LogicException(sprintf(
                '%s registers node type "%s" (%s), which extra.fapost.nodes does not declare.',
                $this->label(),
                $type,
                $handlerClass,
            ));
        }

        if (null === NodeCategory::tryFrom($category) && $category !== $this->solution->id) {
            throw new LogicException(sprintf(
                '%s registers node type "%s" (%s) with category "%s": category() must be a NodeCategory value or the Solution id "%s".',
                $this->label(),
                $type,
                $handlerClass,
                $category,
                $this->solution->id,
            ));
        }

        $this->nodes->register($handlerClass, $this->solution->id);
    }

    public function registerDataAccessor(string $namespace, string $accessorClass): void
    {
        $this->unsupported('registerDataAccessor');
    }

    public function registerRagAdapter(string $provider, string $adapterClass): void
    {
        $this->unsupported('registerRagAdapter');
    }

    public function registerRoutes(Closure $routes): void
    {
        $this->unsupported('registerRoutes');
    }

    public function registerSchedule(Closure $schedule): void
    {
        $this->unsupported('registerSchedule');
    }

    public function registerMigrations(string $path): void
    {
        $this->unsupported('registerMigrations');
    }

    private function unsupported(string $method): never
    {
        throw new LogicException(sprintf('%s calls %s(), which Core does not support for a Solution yet.', $this->label(), $method));
    }

    private function label(): string
    {
        return sprintf('Solution "%s" (%s)', $this->solution->id, $this->solution->package);
    }
}
