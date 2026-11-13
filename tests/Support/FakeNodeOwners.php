<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use LogicException;

/**
 * A node handler registry that only knows who owns which node type, for tests of code that asks nothing else.
 */
final class FakeNodeOwners implements NodeHandlerRegistryInterface
{
    /**
     * @param  array<string, string|null>  $owners  node type => Solution id (null: Core)
     */
    public function __construct(private readonly array $owners = [])
    {
    }

    public function register(string $handlerClass, ?string $owner = null): void
    {
        throw new LogicException('Not supported.');
    }

    public function ownerOf(string $type): ?string
    {
        return $this->owners[$type] ?? null;
    }

    public function has(string $type, int $version): bool
    {
        return array_key_exists($type, $this->owners);
    }

    public function resolve(string $type, int $version): NodeHandlerInterface
    {
        throw new LogicException('Not supported.');
    }

    public function all(): array
    {
        return [];
    }

    public function freeze(): void
    {
    }
}
