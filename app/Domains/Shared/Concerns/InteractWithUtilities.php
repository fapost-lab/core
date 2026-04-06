<?php

declare(strict_types=1);

namespace App\Domains\Shared\Concerns;

use App\Domains\Shared\Contracts\ModelUtilityInterface;

/**
 * Delegates unknown method calls to a dedicated utility class.
 *
 * Models opt in by declaring a `$utilitiesClass` property pointing to a class
 * that implements {@see ModelUtilityInterface}. The utility instance receives
 * the model as a constructor argument via the container.
 *
 * The `app()` call here is an accepted infrastructure exception: Eloquent
 * instantiates models directly, bypassing constructor DI.
 *
 * @property-read class-string<ModelUtilityInterface>|null $utilitiesClass
 */
trait InteractWithUtilities
{
    public function __call($method, $parameters): mixed
    {
        if (
            isset($this->utilitiesClass)
            && is_subclass_of($this->utilitiesClass, ModelUtilityInterface::class)
            && method_exists($this->utilitiesClass, $method)
        ) {
            // Acceptable: Eloquent instantiation bypasses constructor DI.
            return app($this->utilitiesClass, ['model' => $this])->{$method}(...$parameters);
        }

        return parent::__call($method, $parameters);
    }
}
