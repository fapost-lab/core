<?php

declare(strict_types=1);

namespace App\Domains\Flow\Registry;

use App\Domains\Flow\Contracts\TriggerTypeFactoryInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use Fapost\Foundation\Flow\Contracts\TriggerTypeInterface;
use Fapost\Foundation\Solution\Manifest\ManifestSchema;
use LogicException;

/**
 * The trigger types extensions add next to the built-in {@see FlowTriggerType} cases: `type => {class, owner}`.
 *
 * A worker-lifetime singleton, so it keeps classes and builds a type through the factory whenever one is asked for;
 * registration builds one throwaway instance only to read its type. The owner is the id of the Solution that
 * registered the type, or null for Core (a future Core domain such as forms): a Core type never contains a dot and a
 * Solution's always starts with "<id>.", so no two owners can claim one type. A built-in type's name is never taken.
 * Closed after boot ({@see freeze()}).
 */
final class TriggerTypeRegistry
{
    /** What `flow_triggers.type` holds. */
    public const int MAX_TYPE_LENGTH = ManifestSchema::TRIGGER_TYPE_MAX_LENGTH;

    private const string PATTERN = '/^([a-z][a-z0-9_]{1,31}\.)?[a-z][a-z0-9_]*$/D';

    /**
     * @var array<string, array{class: class-string<TriggerTypeInterface>, owner: string|null}>
     */
    private array $types = [];

    private bool $frozen = false;

    public function __construct(
        private readonly TriggerTypeFactoryInterface $factory,
    ) {
    }

    /**
     * @param  class-string<TriggerTypeInterface>  $typeClass
     * @param  string|null  $owner  the registering Solution's id; null for Core
     */
    public function register(string $typeClass, ?string $owner = null): void
    {
        if ($this->frozen) {
            throw new LogicException('Cannot register trigger types after boot.');
        }

        $type = $this->factory->make($typeClass)->type();

        if (1 !== preg_match(self::PATTERN, $type) || mb_strlen($type) > self::MAX_TYPE_LENGTH) {
            throw new LogicException("Trigger type \"{$type}\" ({$typeClass}) must be a snake_case name, \"<solution id>.\" first for a Solution, at most " . self::MAX_TYPE_LENGTH . ' characters.');
        }

        if (null !== FlowTriggerType::tryFrom($type)) {
            throw new LogicException("Trigger type \"{$type}\" ({$typeClass}) is a built-in type.");
        }

        if (null === $owner && str_contains($type, '.')) {
            throw new LogicException("Core trigger type \"{$type}\" must not contain a dot: dotted types belong to Solutions.");
        }

        if (null !== $owner && ! str_starts_with($type, $owner . '.')) {
            throw new LogicException("Trigger type \"{$type}\" of Solution \"{$owner}\" must start with \"{$owner}.\".");
        }

        if (isset($this->types[$type])) {
            throw new LogicException("Duplicate trigger type registration: {$type}");
        }

        $this->types[$type] = ['class' => $typeClass, 'owner' => $owner];
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function get(string $type): TriggerTypeInterface
    {
        if (! isset($this->types[$type])) {
            throw new LogicException("Trigger type not registered: {$type}");
        }

        return $this->factory->make($this->types[$type]['class']);
    }

    /**
     * The Solution that registered the type; null for a Core type and for a type nobody registered.
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
