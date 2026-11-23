<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Flow\Contracts\TriggerTypeFactoryInterface;
use Fapost\Foundation\Flow\Contracts\TriggerTypeInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Builds trigger types through the application container, in the scope of the job or request that asks.
 */
final readonly class ContainerTriggerTypeFactory implements TriggerTypeFactoryInterface
{
    public function __construct(
        private Container $container,
    ) {
    }

    public function make(string $typeClass): TriggerTypeInterface
    {
        return $this->container->make($typeClass);
    }
}
