<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Variables;

use App\Domains\Flow\Contracts\VariableCoercerInterface;
use Carbon\Carbon;
use Throwable;

/**
 * Default coercion rules for user variable types.
 *
 * Rules are purely transformational — no I/O, no tenant context.
 * Bound as a singleton in FlowServiceProvider.
 */
final class VariableCoercer implements VariableCoercerInterface
{
    /**
     * Truthy literals for the `confirm` type (case-insensitive).
     *
     * @var list<string>
     */
    private const array TRUTHY = ['true', '1', 'yes', 'y', 'on', 'да'];

    /**
     * Falsy literals for the `confirm` type (case-insensitive).
     *
     * @var list<string>
     */
    private const array FALSY = ['false', '0', 'no', 'n', 'off', 'нет'];

    public function coerce(mixed $value, VariableType $type): mixed
    {
        return match ($type) {
            VariableType::Number  => $this->coerceNumber($value),
            VariableType::Confirm => $this->coerceConfirm($value),
            VariableType::Date    => $this->coerceDate($value),
            // Structured payloads written by platform handlers — pass through as-is.
            VariableType::Contact,
            VariableType::File,
            VariableType::Photo,
            VariableType::Location => '' === $value ? null : $value,
            // String-semantic types.
            default => $this->coerceString($value),
        };
    }

    private function coerceNumber(mixed $value): int|float|null
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return $value;
        }

        if (!is_string($value) && !is_bool($value)) {
            return null;
        }

        $str = mb_trim((string)$value);

        if (!is_numeric($str)) {
            return null;
        }

        return str_contains($str, '.') ? (float)$str : (int)$str;
    }

    private function coerceConfirm(mixed $value): ?bool
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $lower = mb_strtolower(mb_trim((string)$value));

        if (in_array($lower, self::TRUTHY, true)) {
            return true;
        }

        if (in_array($lower, self::FALSY, true)) {
            return false;
        }

        return null;
    }

    private function coerceDate(mixed $value): ?Carbon
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return Carbon::parse((string)$value);
        } catch (Throwable) {
            return null;
        }
    }

    private function coerceString(mixed $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return is_scalar($value) ? (string)$value : null;
    }
}
