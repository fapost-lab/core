<?php

declare(strict_types=1);

namespace App\Domains\Shared\Infrastructure;

use Closure;
use Fapost\Foundation\Contracts\ModelAttributeResolverInterface;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Container-backed registry for computed model attributes registered by Features/Solutions/Plugins.
 *
 * Implements {@see ModelAttributeResolverInterface} so the fapost/support traits can depend
 * on the contract without coupling to this Core implementation.
 *
 * Used by {@see \Fapost\Support\Concerns\HasComputedAttributes} to resolve computed attributes
 * and to include only {@code append=true} attributes into {@see Model::toArray()} / toJson().
 *
 * @phpstan-type RegisteredEntry array{0: Closure(Model): mixed, 1: bool} // [resolver, append]
 */
final class ModelAttributeRegistry implements ModelAttributeResolverInterface
{
    /**
     * @var array<string, array<string, RegisteredEntry>>
     */
    private array $entries = [];
    private bool $frozen   = false;

    /**
     * Register resolver for a computed attribute on a given model class.
     *
     * If {@code append=true}, the attribute will be included into array/json output.
     *
     * @param  Closure(Model): mixed  $resolver
     */
    public function register(string $modelClass, string $name, Closure $resolver, bool $append = false): void
    {
        if ($this->frozen) {
            throw new LogicException('ModelAttributeRegistry is frozen and cannot be modified.');
        }

        if (isset($this->entries[$modelClass][$name])) {
            throw new LogicException(
                sprintf(
                    'Model attribute [%s] is already registered on [%s].',
                    $name,
                    $modelClass,
                )
            );
        }

        $this->entries[$modelClass][$name] = [$resolver, $append];
    }

    /**
     * Check whether a computed attribute resolver is registered.
     */
    public function has(string $modelClass, string $name): bool
    {
        return isset($this->entries[$modelClass][$name]);
    }

    /**
     * Resolve a computed attribute value for the provided model instance.
     *
     * @param  string  $modelClass
     * @param  string  $name
     * @param  Model   $model
     *
     * @return mixed
     * @throws LogicException If the attribute is not registered.
     *
     */
    public function resolve(string $modelClass, string $name, Model $model): mixed
    {
        if (! isset($this->entries[$modelClass][$name])) {
            throw new LogicException(
                sprintf(
                    'No model attribute [%s] registered on [%s].',
                    $name,
                    $modelClass,
                )
            );
        }

        return ($this->entries[$modelClass][$name][0])($model);
    }

    /**
     * Return resolvers for computed attributes that should be included into array/json output.
     *
     * @return array<string, Closure(Model): mixed>
     */
    public function serializable(string $modelClass): array
    {
        $out = [];

        foreach ($this->entries[$modelClass] ?? [] as $name => [$resolver, $shouldAppend]) {
            if ($shouldAppend) {
                $out[$name] = $resolver;
            }
        }

        return $out;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }
}
