<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Flow\Contracts\CommandExtensionFactoryInterface;
use Fapost\Foundation\Flow\Commands\CommandActionTypeInterface;
use Fapost\Foundation\Flow\Commands\SolutionCommandInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Builds command action types and Solution commands through the application container, in the scope of the job or
 * request that asks.
 */
final readonly class ContainerCommandExtensionFactory implements CommandExtensionFactoryInterface
{
    public function __construct(
        private Container $container,
    ) {
    }

    public function makeActionType(string $typeClass): CommandActionTypeInterface
    {
        return $this->container->make($typeClass);
    }

    public function makeCommand(string $commandClass): SolutionCommandInterface
    {
        return $this->container->make($commandClass);
    }
}
