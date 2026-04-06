<?php

declare(strict_types=1);

namespace App\Domains\Flow\Registry;

use App\Domains\Flow\Contracts\NodeHandlerInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use LogicException;

final class NodeHandlerRegistry implements NodeHandlerRegistryInterface
{
    /**
     * @var array<string, NodeHandlerInterface>
     */
    private array $handlers = [];

    private bool $frozen = false;

    public function register(NodeHandlerInterface $handler): void
    {
        if ($this->frozen) {
            throw new LogicException('Cannot register handlers after boot.');
        }

        if ( ! in_array($handler->version(), $handler->supportedVersions(), true)) {
            throw new LogicException(
                "Handler {$handler->type()}@{$handler->version()} must include own version in supportedVersions()."
            );
        }

        $key = $this->key($handler->type(), $handler->version());

        if (isset($this->handlers[$key])) {
            throw new LogicException("Duplicate handler registration: {$key}");
        }

        $this->handlers[$key] = $handler;
    }

    public function resolve(string $type, int $version): NodeHandlerInterface
    {
        $key = $this->key($type, $version);

        if ( ! isset($this->handlers[$key])) {
            throw new LogicException("Handler not found: {$key}");
        }

        return $this->handlers[$key];
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    private function key(string $type, int $version): string
    {
        return "{$type}@{$version}";
    }
}
