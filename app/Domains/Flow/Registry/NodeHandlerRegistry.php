<?php

declare(strict_types=1);

namespace App\Domains\Flow\Registry;

use App\Domains\Flow\Contracts\NodeHandlerFactoryInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\Flow\Enums\NodeCategory;
use LogicException;

/**
 * Maps `type@version` to a handler class and builds the handler on every resolve.
 *
 * The registry is a worker-lifetime singleton, so it keeps classes rather than
 * instances: an instance would pin the scoped collaborators of whichever job
 * built it. Registration builds one throwaway instance only to read the handler's
 * identity, because {@see NodeHandlerInterface} exposes it through instance methods.
 *
 * Every registration names its owner: the id of the Solution that registers the node type, or null for
 * Core. A Core type never contains a dot and a Solution's always starts with "<id>.", so no two owners
 * can claim one type; the owner is what the engine, the state namespace policy and the builder ask.
 */
final class NodeHandlerRegistry implements NodeHandlerRegistryInterface
{
    /**
     * @var array<string, array{class: class-string<NodeHandlerInterface>, owner: string|null}>
     */
    private array $handlers = [];

    /**
     * @var array<string, string|null>
     */
    private array $owners = [];

    /**
     * @var array<string, int>
     */
    private array $latestVersions = [];

    private bool $frozen = false;

    public function __construct(
        private readonly NodeHandlerFactoryInterface $factory,
    ) {
    }

    public function register(string $handlerClass, ?string $owner = null): void
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

        $this->assertOwnership($type, $owner);
        $this->assertCategory($type, $handler->category(), $owner);

        $key = $this->key($type, $version);

        if (isset($this->handlers[$key])) {
            throw new LogicException("Duplicate handler registration: {$key}");
        }

        $this->handlers[$key]        = ['class' => $handlerClass, 'owner' => $owner];
        $this->owners[$type]         = $owner;
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

        return $this->factory->make($this->handlers[$key]['class']);
    }

    public function ownerOf(string $type): ?string
    {
        return $this->owners[$type] ?? null;
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

    private function assertOwnership(string $type, ?string $owner): void
    {
        if (null === $owner) {
            if (str_contains($type, '.')) {
                throw new LogicException("Core handler type \"{$type}\" must not contain a dot: dotted types belong to Solutions.");
            }

            return;
        }

        if (!str_starts_with($type, $owner . '.')) {
            throw new LogicException("Handler type \"{$type}\" of Solution \"{$owner}\" must start with \"{$owner}.\".");
        }

        if (array_key_exists($type, $this->owners) && $this->owners[$type] !== $owner) {
            throw new LogicException("Handler type \"{$type}\" is already registered by another owner.");
        }
    }

    /**
     * A handler's palette group is a {@see NodeCategory} value; a Solution's node may also be grouped under the
     * Solution's own id. Anything else would open a new, unnamed palette group.
     */
    private function assertCategory(string $type, string $category, ?string $owner): void
    {
        if (null !== NodeCategory::tryFrom($category) || (null !== $owner && $category === $owner)) {
            return;
        }

        $allowed = null === $owner
            ? 'a NodeCategory value'
            : "a NodeCategory value or the Solution id \"{$owner}\"";

        throw new LogicException("Handler \"{$type}\" returns category \"{$category}\" from category(); it must be {$allowed}.");
    }

    private function key(string $type, int $version): string
    {
        return "{$type}@{$version}";
    }
}
