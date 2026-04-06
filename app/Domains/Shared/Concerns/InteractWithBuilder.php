<?php

declare(strict_types=1);

namespace App\Domains\Shared\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Allows a model to declare a custom Eloquent query builder class.
 *
 * Models opt in by declaring a `$customBuilder` property with the FQCN
 * of a class that extends {@see Builder}.
 *
 * @property-read class-string<Builder>|null $customBuilder
 */
trait InteractWithBuilder
{
    public function newEloquentBuilder($query): Builder
    {
        if (isset($this->customBuilder)) {
            return new $this->customBuilder($query);
        }

        return new Builder($query);
    }
}
