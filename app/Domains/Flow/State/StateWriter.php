<?php

declare(strict_types=1);

namespace App\Domains\Flow\State;

use App\Domains\Flow\State\Resolvers\NamespaceResolverRegistry;

final readonly class StateWriter
{
    public function __construct(
        private NamespaceResolverRegistry $registry,
        private FlowState $state,
    ) {
    }

    public function set(string|StatePath $path, mixed $value, WriteContext $context): void
    {
        $statePath = $path instanceof StatePath ? $path : StatePath::from($path);
        $resolver  = $this->registry->for($statePath->namespace);
        $resolver->set($statePath, $value, $this->state, $context);
    }
}
