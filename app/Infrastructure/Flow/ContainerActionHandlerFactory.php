<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Flow\Contracts\ActionHandlerFactoryInterface;
use Fapost\Foundation\Action\ActionHandlerInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Builds action handlers through the application container. The container lives for the whole
 * worker, so each call resolves the scoped bindings of the job that is running at that moment.
 */
final readonly class ContainerActionHandlerFactory implements ActionHandlerFactoryInterface
{
    public function __construct(
        private Container $container,
    ) {
    }

    public function make(string $handlerClass): ActionHandlerInterface
    {
        return $this->container->make($handlerClass);
    }
}
