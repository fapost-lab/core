<?php

declare(strict_types=1);

namespace App\Domains\Flow\Commands;

use App\Domains\Flow\Contracts\CommandExtensionFactoryInterface;
use Fapost\Foundation\Flow\Commands\SolutionCommandInterface;
use Fapost\Foundation\Solution\Manifest\ManifestSchema;
use LogicException;

/**
 * The ready-made chat commands Solutions bring: `command => {class, owner}`, the middle layer of the lookup
 * (tenant, Solution, built-in; ADR-09 § Global Commands).
 *
 * A worker-lifetime singleton that keeps classes and builds a command through the factory whenever it is read. A
 * command carries no owner prefix, so the registry itself refuses a built-in command and a second owner of one
 * command; two installed packages that declare one command never reach it, since discovery leaves both out.
 * Closed after boot ({@see freeze()}).
 */
final class SolutionCommandRegistry
{
    /**
     * @var array<string, array{class: class-string<SolutionCommandInterface>, owner: string}>
     */
    private array $commands = [];

    private bool $frozen = false;

    public function __construct(
        private readonly CommandExtensionFactoryInterface $factory,
        private readonly BuiltinCommandsRegistry $builtins,
    ) {
    }

    /**
     * @param  class-string<SolutionCommandInterface>  $commandClass
     * @param  string  $owner  the registering Solution's id
     */
    public function register(string $commandClass, string $owner): void
    {
        if ($this->frozen) {
            throw new LogicException('Cannot register Solution commands after boot.');
        }

        $command = $this->factory->makeCommand($commandClass)->command();

        if (1 !== preg_match(ManifestSchema::COMMAND_PATTERN, $command)) {
            throw new LogicException("Command \"{$command}\" ({$commandClass}) must be \"/\" followed by 1 to 32 lowercase letters, digits or underscores.");
        }

        if ($this->builtins->has($command)) {
            throw new LogicException("Command \"{$command}\" ({$commandClass}) is a built-in command.");
        }

        if (isset($this->commands[$command])) {
            throw new LogicException("Duplicate command registration: {$command}");
        }

        $this->commands[$command] = ['class' => $commandClass, 'owner' => $owner];
    }

    public function has(string $command): bool
    {
        return isset($this->commands[$command]);
    }

    public function get(string $command): SolutionCommandInterface
    {
        if (! isset($this->commands[$command])) {
            throw new LogicException("Command not registered: {$command}");
        }

        return $this->factory->makeCommand($this->commands[$command]['class']);
    }

    /**
     * The Solution that registered the command; null for a command nobody registered.
     */
    public function ownerOf(string $command): ?string
    {
        return $this->commands[$command]['owner'] ?? null;
    }

    /**
     * Every registered command, in registration order.
     *
     * @return list<string>
     */
    public function commands(): array
    {
        return array_keys($this->commands);
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }
}
