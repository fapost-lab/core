<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use Fapost\Foundation\Contracts\NodeHandlerInterface;

/**
 * Builds a node handler with the collaborators of the scope it is called in.
 *
 * The handler registry outlives every queue job on a worker, while most handler
 * collaborators (message sender, content translator, media services) are scoped
 * to one job. The registry therefore keeps handler classes and asks this factory
 * for a fresh instance on each resolve, so a handler never carries one job's
 * tenant, assistant or settings into the next.
 */
interface NodeHandlerFactoryInterface
{
    /**
     * @template T of NodeHandlerInterface
     *
     * @param  class-string<T>  $handlerClass
     *
     * @return T
     */
    public function make(string $handlerClass): NodeHandlerInterface;
}
