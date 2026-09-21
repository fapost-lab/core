<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Flow\Contracts\NodeHandlerFactoryInterface;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Builds handlers through the application container. The container itself lives
 * for the whole worker, so each call resolves the scoped bindings of the job
 * that is running at that moment.
 */
final readonly class ContainerNodeHandlerFactory implements NodeHandlerFactoryInterface
{
    public function __construct(
        private Container $container,
    ) {
    }

    public function make(string $handlerClass): NodeHandlerInterface
    {
        return $this->container->make($handlerClass);
    }
}
