<?php

declare(strict_types=1);

namespace App\Domains\Flow\Action;

use FAPost\Foundation\Action\ActionHandlerInterface;
use LogicException;

/**
 * In-memory registry of {@see ActionHandlerInterface} implementations.
 *
 * Built-in actions (if any) registered in CoreBootstrap; plugin/solution
 * actions registered via CoreRegistrar. Fail-on-conflict policy: duplicate
 * id() raises {@see LogicException} at registration time.
 *
 * Lookup is O(1) by id; the call node's {@code handler} transport queries
 * this registry once per execute().
 */
final class ActionHandlerRegistry
{
    /** @var array<string, ActionHandlerInterface> */
    private array $handlers = [];

    private bool $frozen = false;

    public function register(ActionHandlerInterface $handler): void
    {
        $id = $handler->id();

        if ($this->frozen) {
            throw new LogicException("ActionHandlerRegistry is frozen; cannot register '{$id}'.");
        }

        if (isset($this->handlers[$id])) {
            throw new LogicException(sprintf(
                "Action handler '%s' already registered. Existing: %s, conflicting: %s.",
                $id,
                $this->handlers[$id]::class,
                $handler::class,
            ));
        }

        $this->handlers[$id] = $handler;
    }

    public function has(string $id): bool
    {
        return isset($this->handlers[$id]);
    }

    public function get(string $id): ActionHandlerInterface
    {
        return $this->handlers[$id]
            ?? throw new LogicException("Action handler '{$id}' is not registered.");
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->handlers);
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }
}
