<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use Fapost\Foundation\Action\ActionHandlerInterface;

/**
 * Builds an action handler with the collaborators of the scope it is called in.
 *
 * The action registry outlives every queue job on a worker, while a handler may depend on scoped
 * services (the current tenant, a connection). The registry therefore keeps handler classes and asks
 * this factory for a fresh instance on each call, so a handler never carries one job's state into
 * the next. Same reasoning as {@see NodeHandlerFactoryInterface}.
 */
interface ActionHandlerFactoryInterface
{
    /**
     * @template T of ActionHandlerInterface
     *
     * @param  class-string<T>  $handlerClass
     *
     * @return T
     */
    public function make(string $handlerClass): ActionHandlerInterface;
}
