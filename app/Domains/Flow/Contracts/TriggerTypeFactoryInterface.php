<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use Fapost\Foundation\Flow\Contracts\TriggerTypeInterface;

/**
 * Builds a registered trigger type. The registry outlives every job on a worker, so it keeps classes and asks this
 * factory for a fresh instance whenever it needs one, as the node handler registry does.
 */
interface TriggerTypeFactoryInterface
{
    /**
     * @param  class-string<TriggerTypeInterface>  $typeClass
     */
    public function make(string $typeClass): TriggerTypeInterface;
}
