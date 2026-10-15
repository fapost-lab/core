<?php

declare(strict_types=1);

namespace App\Infrastructure\Solution;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Solution\DTO\InstalledSolution;
use Closure;
use Fapost\Foundation\Action\ActionHandlerInterface;
use Fapost\Foundation\Contracts\CoreRegistrarInterface;
use LogicException;

/**
 * The Core side of {@see CoreRegistrarInterface} for one Solution.
 *
 * A Solution registers action handlers and nothing else for now. Every other call fails at once with
 * a message naming the Solution, because a quiet no-op would show up at a client, not on the author's
 * first run. An action must be declared in the Solution's manifest (`extra.fapost.actions`); that,
 * together with the manifest rule that every action starts with the Solution id, is what keeps one
 * Solution out of another's (or Core's) namespace.
 */
final readonly class SolutionCoreRegistrar implements CoreRegistrarInterface
{
    public function __construct(
        private InstalledSolution $solution,
        private ActionHandlerRegistry $actions,
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
        throw new LogicException(sprintf(
            '%s calls registerNodeHandler(%s): a Solution registers action handlers, not node types. Use registerActionHandler().',
            $this->label(),
            $handlerClass,
        ));
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
