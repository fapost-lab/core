<?php

declare(strict_types=1);

namespace App\Domains\Shared\Infrastructure;

use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Container-backed registry for computed model attributes registered by Features/Solutions/Plugins.
 *
 * Used by {@see \App\Domains\Shared\Models\BaseModel} to resolve computed attributes via {@see __get()}
 * and to include only {@code append=true} attributes into {@see Model::toArray()}
 * / {@see Model::toJson()}.
 *
 * @phpstan-type RegisteredEntry array{0: Closure(Model): mixed, 1: bool} // [resolver, append]
 */
final class ModelAttributeRegistry
{
    /**
     * @var array<string, array<string, RegisteredEntry>>
     */
    private array $entries = [];

    /**
     * Register resolver for a computed attribute on a given model class.
     *
     * If {@code append=true}, the attribute will be included into array/json output.
     *
     * @param  Closure(Model): mixed  $resolver
     */
    public function register(string $modelClass, string $name, Closure $resolver, bool $append = false): void
    {
        if (isset($this->entries[$modelClass][$name])) {
            throw new LogicException(sprintf(
                'Model attribute [%s] is already registered on [%s].',
                $name,
                $modelClass,
            ));
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
     * @throws LogicException If the attribute is not registered.
     *
     * @param  string                               $modelClass
     * @param  string                               $name
     * @param  Model  $model
     *
     * @return mixed
     */
    public function resolve(string $modelClass, string $name, Model $model): mixed
    {
        if ( ! isset($this->entries[$modelClass][$name])) {
            throw new LogicException(sprintf(
                'No model attribute [%s] registered on [%s].',
                $name,
                $modelClass,
            ));
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
}
