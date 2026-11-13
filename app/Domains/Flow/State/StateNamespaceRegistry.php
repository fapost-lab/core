<?php

declare(strict_types=1);

namespace App\Domains\Flow\State;

use Fapost\Foundation\Flow\Contracts\StateNamespacesInterface;
use Fapost\Foundation\Flow\Enums\StateNamespace;
use LogicException;

/**
 * The state namespaces known to this process: Core's built-in ones ({@see StateNamespace}) and one per Solution
 * that declares `state: true`, named by the Solution's id and owned by it.
 *
 * Image-level like the node handler registry: filled once while the application boots, from the Solution
 * catalog, and never per tenant. Whether the owning Solution is active for a tenant is not asked here; data
 * written into an inactive Solution's namespace simply stays in the session.
 */
final class StateNamespaceRegistry implements StateNamespacesInterface
{
    /**
     * @var array<string, string> namespace => id of the owning Solution
     */
    private array $owners = [];

    private bool $frozen = false;

    /**
     * Gives the Solution the namespace named by its id.
     */
    public function registerSolutionNamespace(string $solutionId): void
    {
        if ($this->frozen) {
            throw new LogicException('Cannot register state namespaces after boot.');
        }

        if (null !== StateNamespace::tryFrom($solutionId)) {
            throw new LogicException("State namespace \"{$solutionId}\" is built in and cannot belong to a Solution.");
        }

        $this->owners[$solutionId] = $solutionId;
    }

    public function all(): array
    {
        return [
            ...array_map(static fn (StateNamespace $namespace): string => $namespace->value, StateNamespace::cases()),
            ...array_keys($this->owners),
        ];
    }

    public function has(string $namespace): bool
    {
        return null !== StateNamespace::tryFrom($namespace) || isset($this->owners[$namespace]);
    }

    public function ownerOf(string $namespace): ?string
    {
        return $this->owners[$namespace] ?? null;
    }

    /**
     * Namespaces of the Solutions only.
     *
     * @return list<string>
     */
    public function solutionNamespaces(): array
    {
        return array_keys($this->owners);
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }
}
