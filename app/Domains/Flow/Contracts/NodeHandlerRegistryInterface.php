<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use FAPost\Foundation\Contracts\NodeHandlerInterface;

interface NodeHandlerRegistryInterface
{
    public function register(NodeHandlerInterface $handler): void;

    public function resolve(string $type, int $version): NodeHandlerInterface;

    /**
     * @return list<NodeHandlerInterface>
     */
    public function all(): array;

    public function freeze(): void;
}
