<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Resolvers;

use App\Domains\Flow\State\Exceptions\ReadonlyNamespaceException;
use App\Domains\Flow\State\FlowState;
use App\Domains\Flow\State\NamespaceWritePolicy;
use App\Domains\Flow\State\StateNamespace;
use App\Domains\Flow\State\StatePath;
use App\Domains\Flow\State\WriteContext;
use InvalidArgumentException;

final readonly class SessionStateResolver implements NamespaceResolverInterface
{
    public function __construct(
        private StateNamespace $namespace,
    ) {
        if (StateNamespace::Module === $namespace) {
            throw new InvalidArgumentException('Use ModuleStateResolver for module namespace.');
        }

        if (StateNamespace::Rag === $namespace) {
            throw new InvalidArgumentException('Use RagStateResolver for rag namespace.');
        }
    }

    public function get(StatePath $path, FlowState $state): mixed
    {
        return $state->get($path);
    }

    public function set(StatePath $path, mixed $value, FlowState $state, WriteContext $context): void
    {
        $policy = $this->namespace->writePolicy();

        if (NamespaceWritePolicy::EngineOnly === $policy && ! $context->isEngine()) {
            throw new ReadonlyNamespaceException(
                "Namespace '{$this->namespace->value}' is engine-only. Got write context type '{$context->type}'."
            );
        }

        $state->set($path, $value);
    }
}
