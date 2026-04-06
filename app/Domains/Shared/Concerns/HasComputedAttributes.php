<?php

declare(strict_types=1);

namespace App\Domains\Shared\Concerns;

use App\Domains\Shared\Infrastructure\ModelAttributeRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * Adds computed attribute support to Eloquent models via {@see ModelAttributeRegistry}.
 *
 * The `app()` calls here are an accepted infrastructure exception: Eloquent instantiates
 * models directly, bypassing constructor DI, so service-locator is the only viable pattern.
 *
 * @mixin Model
 */
trait HasComputedAttributes
{
    /**
     * Resolve computed attributes registered in {@see ModelAttributeRegistry}.
     *
     * Fallbacks to Eloquent's default attribute resolution when the attribute is not registered.
     *
     * @throws \Illuminate\Contracts\Container\CircularDependencyException
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function __get($key): mixed
    {
        // Acceptable: Eloquent instantiation bypasses constructor DI.
        $registry = app(ModelAttributeRegistry::class);

        if ($registry->has(static::class, (string) $key)) {
            return $registry->resolve(static::class, (string) $key, $this);
        }

        return parent::__get($key);
    }

    /**
     * Convert model to an attribute array and append registry attributes marked with {@code append=true}.
     *
     * Note: resolver closures may access relations; callers should eager-load to avoid N+1 queries.
     *
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();

        // Acceptable: Eloquent instantiation bypasses constructor DI.
        $registry = app(ModelAttributeRegistry::class);
        $extra    = $registry->serializable(static::class);

        foreach ($extra as $name => $resolver) {
            $attributes[$name] = $resolver($this);
        }

        return $attributes;
    }
}
