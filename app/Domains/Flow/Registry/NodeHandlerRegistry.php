<?php

declare(strict_types=1);

namespace App\Domains\Flow\Registry;

use App\Domains\Flow\Contracts\NodeHandlerFactoryInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use LogicException;

/**
 * Maps `type@version` to a handler class and builds the handler on every resolve.
 *
 * The registry is a worker-lifetime singleton, so it keeps classes rather than
 * instances: an instance would pin the scoped collaborators of whichever job
 * built it. Registration builds one throwaway instance only to read the handler's
 * identity, because {@see NodeHandlerInterface} exposes it through instance methods.
 */
final class NodeHandlerRegistry implements NodeHandlerRegistryInterface
{
    /**
     * @var array<string, class-string<NodeHandlerInterface>>
     */
    private array $handlers = [];

    /**
     * @var array<string, int>
     */
    private array $latestVersions = [];

    private bool $frozen = false;

    public function __construct(
        private readonly NodeHandlerFactoryInterface $factory,
    ) {
    }

    public function register(string $handlerClass): void
    {
        if ($this->frozen) {
            throw new LogicException('Cannot register handlers after boot.');
        }

        $handler = $this->factory->make($handlerClass);
        $type    = $handler->type();
        $version = $handler->version();

        if (! in_array($version, $handler->supportedVersions(), true)) {
            throw new LogicException(
                "Handler {$type}@{$version} must include own version in supportedVersions()."
            );
        }

        $key = $this->key($type, $version);

        if (isset($this->handlers[$key])) {
            throw new LogicException("Duplicate handler registration: {$key}");
        }

        $this->handlers[$key]        = $handlerClass;
        $this->latestVersions[$type] = max($version, $this->latestVersions[$type] ?? $version);
    }

    public function has(string $type, int $version): bool
    {
        return isset($this->handlers[$this->key($type, $version)]);
    }

    public function resolve(string $type, int $version): NodeHandlerInterface
    {
        $key = $this->key($type, $version);

        if (! isset($this->handlers[$key])) {
            throw new LogicException("Handler not found: {$key}");
        }

        return $this->factory->make($this->handlers[$key]);
    }

    public function all(): array
    {
        $handlers = [];

        foreach ($this->latestVersions as $type => $version) {
            $handlers[] = $this->resolve($type, $version);
        }

        return $handlers;
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
