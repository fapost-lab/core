<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Resolvers;

use App\Domains\Flow\State\Exceptions\ReadonlyNamespaceException;
use App\Domains\Flow\State\FlowState;
use App\Domains\Flow\State\StatePath;
use App\Domains\Flow\State\WriteContext;

final class RagStateResolver implements NamespaceResolverInterface
{
    private const ALLOWED_NODE = 'rag_query';

    public function get(StatePath $path, FlowState $state): mixed
    {
        return $state->get($path);
    }

    public function set(StatePath $path, mixed $value, FlowState $state, WriteContext $context): void
    {
        if ( ! $context->isNode(self::ALLOWED_NODE)) {
            throw new ReadonlyNamespaceException(
                "Namespace 'rag' is node-restricted. Only 'rag_query' node can write. "
                . "Got: type='{$context->type}', name='{$context->name}'."
            );
        }

        $state->set($path, $value);
    }
}
