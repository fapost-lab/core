<?php

declare(strict_types=1);

namespace App\Domains\Flow\Action;

use App\Domains\Flow\Contracts\ActionHandlerFactoryInterface;
use Fapost\Foundation\Action\ActionHandlerInterface;
use LogicException;

/**
 * Registry of {@see ActionHandlerInterface} classes, by action id.
 *
 * It keeps classes and an owner, not instances: the registry is a singleton that lives as long as a
 * worker, while a handler may depend on scoped services, so {@see get()} builds a handler on every
 * call in the scope it is called in ({@see ActionHandlerFactoryInterface}). The owner is the id of the
 * Solution that registered the action, or null for Core.
 *
 * Fail-on-conflict policy: a duplicate id raises {@see LogicException} at registration time, naming
 * both owners. Core's own actions must start with {@see CORE_PREFIXES}; every Solution action starts
 * with its Solution id (the manifest enforces it), so a Solution can never take over a Core action.
 */
final class ActionHandlerRegistry
{
    /** Namespaces reserved for Core's own actions. */
    final public const array CORE_PREFIXES = ['core.', 'platform.'];

    /** @var array<string, array{class: class-string<ActionHandlerInterface>, owner: ?string}> */
    private array $handlers = [];

    private bool $frozen = false;

    public function __construct(
        private readonly ActionHandlerFactoryInterface $factory,
    ) {
    }

    /**
     * Reads the id of a handler class from a throwaway instance. The constructor runs outside any
     * tenant, so it must be cheap and must not touch tenant data.
     *
     * @param  class-string<ActionHandlerInterface>  $handlerClass
     */
    public function idOf(string $handlerClass): string
    {
        $this->assertIsHandler($handlerClass);

        return $this->factory->make($handlerClass)->id();
    }

    /**
     * @param  class-string<ActionHandlerInterface>  $handlerClass
     * @param  string|null  $solutionId  the registering Solution; null for Core
     * @param  string|null  $id  the id when the caller already read it with {@see idOf()}
     *
     * @return string the action id
     */
    public function register(string $handlerClass, ?string $solutionId = null, ?string $id = null): string
    {
        $id ??= $this->idOf($handlerClass);

        if ($this->frozen) {
            throw new LogicException("ActionHandlerRegistry is frozen; cannot register '{$id}'.");
        }

        if (null === $solutionId && !$this->hasCorePrefix($id)) {
            throw new LogicException(sprintf(
                "Core action '%s' (%s) must start with one of: %s.",
                $id,
                $handlerClass,
                implode(', ', self::CORE_PREFIXES),
            ));
        }

        if (isset($this->handlers[$id])) {
            throw new LogicException(sprintf(
                "Action handler '%s' already registered. Existing: %s (owner: %s), conflicting: %s (owner: %s).",
                $id,
                $this->handlers[$id]['class'],
                self::describeOwner($this->handlers[$id]['owner']),
                $handlerClass,
                self::describeOwner($solutionId),
            ));
        }

        $this->handlers[$id] = ['class' => $handlerClass, 'owner' => $solutionId];

        return $id;
    }

    public function has(string $id): bool
    {
        return isset($this->handlers[$id]);
    }

    /**
     * Builds the handler in the current scope. Nothing is cached.
     */
    public function get(string $id): ActionHandlerInterface
    {
        $entry = $this->handlers[$id]
            ?? throw new LogicException("Action handler '{$id}' is not registered.");

        return $this->factory->make($entry['class']);
    }

    /**
     * The Solution that registered the action; null for Core's own actions.
     */
    public function ownerOf(string $id): ?string
    {
        return ($this->handlers[$id] ?? throw new LogicException("Action handler '{$id}' is not registered."))['owner'];
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

    private static function describeOwner(?string $solutionId): string
    {
        return null === $solutionId ? 'Core' : sprintf('Solution "%s"', $solutionId);
    }

    /**
     * @phpstan-assert class-string<ActionHandlerInterface> $handlerClass
     */
    private function assertIsHandler(string $handlerClass): void
    {
        if (!is_a($handlerClass, ActionHandlerInterface::class, true)) {
            throw new LogicException(sprintf('%s is not an action handler: it must implement %s.', $handlerClass, ActionHandlerInterface::class));
        }
    }

    private function hasCorePrefix(string $id): bool
    {
        foreach (self::CORE_PREFIXES as $prefix) {
            if (str_starts_with($id, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
