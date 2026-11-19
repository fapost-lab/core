<?php

declare(strict_types=1);

namespace App\Domains\Credentials;

use Fapost\Foundation\Credentials\Contracts\HttpCredentialTypeInterface;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use ReflectionClass;

/**
 * The credential types, by id. A singleton that keeps classes and no instances (as the node handler registry does,
 * ADR-0001): {@see self::get()} builds the type through the container each time, so a type that takes a scoped
 * collaborator never holds the first job's one.
 *
 * A type's id is read once, when it is registered, from an instance made without its constructor: types hold no
 * state and their id is constant.
 */
final class CredentialTypeRegistry
{
    /** @var array<string, class-string<HttpCredentialTypeInterface>> */
    private array $classes = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param  class-string<HttpCredentialTypeInterface>  $class
     *
     * @throws InvalidArgumentException when the class is not a credential type or its id is taken
     */
    public function register(string $class): void
    {
        if (!is_subclass_of($class, HttpCredentialTypeInterface::class)) {
            throw new InvalidArgumentException(sprintf('"%s" is not an HTTP credential type.', $class));
        }

        $id = (new ReflectionClass($class))->newInstanceWithoutConstructor()->id();

        if (isset($this->classes[$id]) && $this->classes[$id] !== $class) {
            throw new InvalidArgumentException(sprintf('Credential type "%s" is already registered.', $id));
        }

        $this->classes[$id] = $class;
    }

    public function has(string $id): bool
    {
        return isset($this->classes[$id]);
    }

    /**
     * @throws InvalidArgumentException when no type has the id
     */
    public function get(string $id): HttpCredentialTypeInterface
    {
        if (!isset($this->classes[$id])) {
            throw new InvalidArgumentException(sprintf('Unknown credential type "%s".', $id));
        }

        /** @var HttpCredentialTypeInterface $type */
        $type = $this->container->make($this->classes[$id]);

        return $type;
    }

    /**
     * @return list<HttpCredentialTypeInterface>
     */
    public function all(): array
    {
        return array_values(array_map($this->get(...), array_keys($this->classes)));
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->classes);
    }
}
