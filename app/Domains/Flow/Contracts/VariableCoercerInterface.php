<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\State\Variables\VariableType;

/**
 * Coerces a raw state value to the PHP native type declared for a variable.
 *
 * Coercion happens strictly on reads — storage remains JSONB-friendly (strings /
 * JSON-native types). Returning null signals an uncoercible or absent value.
 */
interface VariableCoercerInterface
{
    /**
     * Coerce $value to the PHP native type corresponding to $type.
     *
     * Returns null when $value is absent, empty, or cannot be meaningfully
     * converted. Never throws — invalid input always produces null.
     */
    public function coerce(mixed $value, VariableType $type): mixed;
}
