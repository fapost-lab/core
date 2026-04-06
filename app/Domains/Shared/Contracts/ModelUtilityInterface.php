<?php

declare(strict_types=1);

namespace App\Domains\Shared\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Marker interface for model utility classes resolved via {@see \App\Domains\Shared\Concerns\InteractWithUtilities}.
 *
 * Implementations receive the owning model instance as a constructor argument.
 * Register utilities against a model by setting `$utilitiesClass` on the model.
 */
interface ModelUtilityInterface
{
    public function __construct(Model $model);
}
