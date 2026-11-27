<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use Fapost\Foundation\Flow\Commands\CommandActionTypeInterface;
use Fapost\Foundation\Flow\Commands\SolutionCommandInterface;

/**
 * Builds a registered command action type or Solution command. The registries outlive every job on a worker, so they
 * keep classes and ask this factory for a fresh instance whenever they need one, as the trigger type registry does.
 */
interface CommandExtensionFactoryInterface
{
    /**
     * @param  class-string<CommandActionTypeInterface>  $typeClass
     */
    public function makeActionType(string $typeClass): CommandActionTypeInterface;

    /**
     * @param  class-string<SolutionCommandInterface>  $commandClass
     */
    public function makeCommand(string $commandClass): SolutionCommandInterface;
}
