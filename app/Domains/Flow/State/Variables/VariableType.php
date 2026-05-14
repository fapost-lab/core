<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Variables;

/**
 * Declares the semantic type of a user variable as authored in the flow builder.
 *
 * Runtime coercion applies this type when reading values through VariableResolver
 * so that comparisons in BranchNodeHandler operate on strongly-typed values
 * instead of raw strings from the JSON state.
 */
enum VariableType: string
{
    case Text     = 'text';
    case Number   = 'number';
    case Phone    = 'phone';
    case Email    = 'email';
    case Confirm  = 'confirm';
    case Date     = 'date';
    case Contact  = 'contact';
    case File     = 'file';
    case Photo    = 'photo';
    case Location = 'location';
    case Select   = 'select';
}
