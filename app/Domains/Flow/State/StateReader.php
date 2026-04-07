<?php

declare(strict_types=1);

namespace App\Domains\Flow\State;

use App\Domains\Flow\State\Resolvers\NamespaceResolverRegistry;

final readonly class StateReader
{
    public function __construct(
        private NamespaceResolverRegistry $registry,
        private FlowState $state,
    ) {
    }

    public function get(string|StatePath $path): mixed
    {
        $statePath = $path instanceof StatePath ? $path : StatePath::from($path);
        $resolver  = $this->registry->for($statePath->namespace);

        return $resolver->get($statePath, $this->state);
    }
}
