<?php

declare(strict_types=1);

namespace App\Domains\Flow\Expression;

use FAPost\Foundation\Flow\Contracts\ExpressionEngineInterface;
use FAPost\Foundation\Flow\Contracts\ExpressionEngineNotFoundException;
use LogicException;

/**
 * In-memory registry of {@see ExpressionEngineInterface} implementations.
 *
 * Engines register themselves at boot. Conflicts on id() cause a hard
 * {@see LogicException} — operator-visible failure beats silent override.
 *
 * Resolution is per-flow_definition: the stored {@code expression_engine}
 * snapshot column drives the lookup, so that running sessions evaluate against
 * the engine that was active when the definition was published.
 */
final class ExpressionEngineRegistry
{
    /** @var array<string, ExpressionEngineInterface> */
    private array $engines = [];

    private bool $frozen = false;

    public function register(ExpressionEngineInterface $engine): void
    {
        $id = $engine->id();

        if ($this->frozen) {
            throw new LogicException(
                "ExpressionEngineRegistry is frozen; cannot register '{$id}'."
            );
        }

        if (isset($this->engines[$id])) {
            throw new LogicException(sprintf(
                "Expression engine '%s' already registered. Existing: %s, conflicting: %s.",
                $id,
                $this->engines[$id]::class,
                $engine::class,
            ));
        }

        $this->engines[$id] = $engine;
    }

    /**
     * @throws ExpressionEngineNotFoundException
     */
    public function get(string $id): ExpressionEngineInterface
    {
        return $this->engines[$id]
            ?? throw ExpressionEngineNotFoundException::forId($id);
    }

    public function has(string $id): bool
    {
        return isset($this->engines[$id]);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->engines);
    }

    /**
     * Lock the registry against further registrations. Intended to be called
     * from AppServiceProvider::booted() in non-testing environments to catch
     * runtime registration attempts.
     */
    public function freeze(): void
    {
        $this->frozen = true;
    }
}
