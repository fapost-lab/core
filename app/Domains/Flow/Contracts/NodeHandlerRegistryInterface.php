<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use Fapost\Foundation\Contracts\NodeHandlerInterface;

interface NodeHandlerRegistryInterface
{
    /**
     * @param  class-string<NodeHandlerInterface>  $handlerClass
     * @param  string|null  $owner  id of the Solution that registers the type; null for Core, whose types never contain a dot
     */
    public function register(string $handlerClass, ?string $owner = null): void;

    /**
     * The id of the Solution that owns the node type, or null for a Core type or a type that is not registered.
     */
    public function ownerOf(string $type): ?string;

    /**
     * Whether a handler is registered under `type@version`, without building it.
     */
    public function has(string $type, int $version): bool;

    /**
     * Build the handler registered under `type@version` in the current scope.
     */
    public function resolve(string $type, int $version): NodeHandlerInterface;

    /**
     * @return list<NodeHandlerInterface>
     */
    public function all(): array;

    public function freeze(): void;
}
