<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use Fapost\Foundation\Contracts\NodeHandlerInterface;

interface NodeHandlerRegistryInterface
{
    /**
     * @param  class-string<NodeHandlerInterface>  $handlerClass
     */
    public function register(string $handlerClass): void;

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
