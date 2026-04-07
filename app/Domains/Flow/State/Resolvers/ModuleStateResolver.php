<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Resolvers;

use App\Domains\Flow\State\Exceptions\ReadonlyNamespaceException;
use App\Domains\Flow\State\FlowState;
use App\Domains\Flow\State\StatePath;
use App\Domains\Flow\State\WriteContext;
use App\Domains\Shared\Registries\ModuleNamespaceRegistry;

final class ModuleStateResolver implements NamespaceResolverInterface
{
    public function __construct(
        private readonly ModuleNamespaceRegistry $registry,
    ) {
    }

    public function get(StatePath $path, FlowState $state): mixed
    {
        $owner = $path->owner;

        if (null === $owner) {
            throw new ReadonlyNamespaceException("Namespace 'module' requires owner segment.");
        }

        return $this->registry->for($owner)->get($path->leaf);
    }

    public function set(StatePath $path, mixed $value, FlowState $state, WriteContext $context): void
    {
        $owner = $path->owner;

        if (null === $owner) {
            throw new ReadonlyNamespaceException("Namespace 'module' requires owner segment.");
        }

        if ( ! $context->isAccessor($owner)) {
            throw new ReadonlyNamespaceException(
                "Namespace 'module' is accessor-only. Direct write is forbidden. "
                . "Got context type '{$context->type}' with name '{$context->name}'."
            );
        }

        $this->registry->for($owner)->set($path->leaf, $value);
    }
}
