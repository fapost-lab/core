<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Flow\Enums\InputExpectedType;
use Fapost\Foundation\DTO\IncomingMessage;

/**
 * Validates the user-provided payload of an `input` node against the
 * declared expected type and per-type rules.
 *
 * The validator owns the *parsing* (e.g. "+1 (415) 555 0123" → "+14155550123"),
 * not just the boolean check — handlers store the normalized value, not the
 * raw user text.
 */
interface InputValidatorInterface
{
    /**
     * @param  array<string, mixed>  $rules     Flat per-type rule bag from node config (`config.validation`).
     * @param  array<string, mixed>  $nodeConfig Full node config; needed for select/confirm to read the button list.
     */
    public function validate(
        InputExpectedType $type,
        IncomingMessage $incoming,
        array $rules,
        array $nodeConfig,
    ): ValidationResult;
}
