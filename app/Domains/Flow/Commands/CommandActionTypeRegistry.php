<?php

declare(strict_types=1);

namespace App\Domains\Flow\Commands;

use App\Domains\Flow\Contracts\CommandExtensionFactoryInterface;
use Fapost\Foundation\Flow\Commands\CommandActionTypeInterface;
use Fapost\Foundation\Solution\Manifest\ManifestSchema;
use LogicException;

/**
 * The command action types Solutions add next to the built-in {@see CommandActionType} cases: `type => {class, owner}`.
 *
 * A worker-lifetime singleton, so it keeps classes and builds a type through the factory whenever one is asked for;
 * registration builds one throwaway instance only to read its type. The owner is the id of the Solution that
 * registered it: a registered type always starts with "<owner>.", and a built-in type carries no dot, so no two owners
 * can claim one type and a built-in name is never taken. Closed after boot ({@see freeze()}).
 */
final class CommandActionTypeRegistry
{
    /** What an entry of `assistants.commands` may hold as its type. */
    public const int MAX_TYPE_LENGTH = ManifestSchema::COMMAND_TYPE_MAX_LENGTH;

    private const string PATTERN = '/^[a-z][a-z0-9_]{1,31}\.[a-z][a-z0-9_]*$/D';

    /**
     * @var array<string, array{class: class-string<CommandActionTypeInterface>, owner: string}>
     */
    private array $types = [];

    private bool $frozen = false;

    public function __construct(
        private readonly CommandExtensionFactoryInterface $factory,
    ) {
    }

    /**
     * @param  class-string<CommandActionTypeInterface>  $typeClass
     * @param  string  $owner  the registering Solution's id
     */
    public function register(string $typeClass, string $owner): void
    {
        if ($this->frozen) {
            throw new LogicException('Cannot register command action types after boot.');
        }

        $type = $this->factory->makeActionType($typeClass)->type();

        if (1 !== preg_match(self::PATTERN, $type) || mb_strlen($type) > self::MAX_TYPE_LENGTH) {
            throw new LogicException("Command action type \"{$type}\" ({$typeClass}) must be \"<solution id>.\" and a snake_case name, at most " . self::MAX_TYPE_LENGTH . ' characters.');
        }

        if (! str_starts_with($type, $owner . '.')) {
            throw new LogicException("Command action type \"{$type}\" of Solution \"{$owner}\" must start with \"{$owner}.\".");
        }

        if (isset($this->types[$type])) {
            throw new LogicException("Duplicate command action type registration: {$type}");
        }

        $this->types[$type] = ['class' => $typeClass, 'owner' => $owner];
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function get(string $type): CommandActionTypeInterface
    {
        if (! isset($this->types[$type])) {
            throw new LogicException("Command action type not registered: {$type}");
        }

        return $this->factory->makeActionType($this->types[$type]['class']);
    }

    /**
     * The Solution that registered the type; null for a type nobody registered.
     */
    public function ownerOf(string $type): ?string
    {
        return $this->types[$type]['owner'] ?? null;
    }

    /**
     * Every registered type, in registration order.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->types);
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }
}
