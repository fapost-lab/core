<?php

declare(strict_types=1);

namespace App\Domains\Flow\Rag;

use Fapost\Foundation\Contracts\RagAdapterInterface;
use LogicException;

/**
 * Fail-on-conflict registry of {@see RagAdapterInterface} implementations,
 * keyed by provider id. Mirrors the Node/Transport/Action registries: writes
 * are blocked once {@see freeze()} runs at app boot, and double-registration
 * of the same provider is treated as a programming error.
 */
final class RagAdapterRegistry
{
    /**
     * @var array<string, RagAdapterInterface>
     */
    private array $adapters = [];

    private bool $frozen = false;

    public function register(RagAdapterInterface $adapter): void
    {
        if ($this->frozen) {
            throw new LogicException('RagAdapterRegistry is frozen and cannot accept new adapters.');
        }

        $provider = $adapter->provider();

        if ('' === $provider) {
            throw new LogicException('RAG adapter provider must be a non-empty string.');
        }

        if (isset($this->adapters[$provider])) {
            throw new LogicException(
                "RAG adapter '{$provider}' is already registered; conflicting registration rejected.",
            );
        }

        $this->adapters[$provider] = $adapter;
    }

    public function has(string $provider): bool
    {
        return isset($this->adapters[$provider]);
    }

    public function get(string $provider): RagAdapterInterface
    {
        if (! isset($this->adapters[$provider])) {
            throw new LogicException("RAG adapter '{$provider}' is not registered.");
        }

        return $this->adapters[$provider];
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * @return list<string>
     */
    public function providers(): array
    {
        return array_keys($this->adapters);
    }
}
