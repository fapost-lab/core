<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call;

use Fapost\Foundation\Flow\Call\CallTransportInterface;
use LogicException;

/**
 * In-memory registry of {@see CallTransportInterface} implementations.
 *
 * V1 ships {@code http} and {@code handler}. Plugin/Solution transports
 * register via CoreRegistrar. Fail-on-conflict policy on id().
 */
final class CallTransportRegistry
{
    /** @var array<string, CallTransportInterface> */
    private array $transports = [];

    private bool $frozen = false;

    public function register(CallTransportInterface $transport): void
    {
        $id = $transport->id();

        if ($this->frozen) {
            throw new LogicException("CallTransportRegistry is frozen; cannot register '{$id}'.");
        }

        if (isset($this->transports[$id])) {
            throw new LogicException(sprintf(
                "Call transport '%s' already registered. Existing: %s, conflicting: %s.",
                $id,
                $this->transports[$id]::class,
                $transport::class,
            ));
        }

        $this->transports[$id] = $transport;
    }

    public function has(string $id): bool
    {
        return isset($this->transports[$id]);
    }

    public function get(string $id): CallTransportInterface
    {
        return $this->transports[$id]
            ?? throw new LogicException("Call transport '{$id}' is not registered.");
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->transports);
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }
}
