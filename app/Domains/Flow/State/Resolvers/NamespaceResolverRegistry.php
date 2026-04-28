<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Resolvers;

use App\Domains\Flow\State\StateNamespace;
use LogicException;

final class NamespaceResolverRegistry
{
    /** @var array<string, NamespaceResolverInterface> */
    private array $resolvers = [];
    private bool $frozen = false;

    public function register(StateNamespace $namespace, NamespaceResolverInterface $resolver): void
    {
        if ($this->frozen) {
            throw new LogicException('NamespaceResolverRegistry is frozen and cannot be modified.');
        }

        $this->resolvers[$namespace->value] = $resolver;
    }

    public function for(StateNamespace $namespace): NamespaceResolverInterface
    {
        if (!isset($this->resolvers[$namespace->value])) {
            throw new LogicException("No resolver registered for namespace '{$namespace->value}'");
        }

        return $this->resolvers[$namespace->value];
    }

    public function freeze(): void
    {
        foreach (StateNamespace::cases() as $namespace) {
            if (!isset($this->resolvers[$namespace->value])) {
                throw new LogicException("No resolver registered for namespace '{$namespace->value}'");
            }
        }

        $this->frozen = true;
    }
}
