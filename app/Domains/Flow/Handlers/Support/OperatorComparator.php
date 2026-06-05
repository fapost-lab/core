<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers\Support;

use App\Domains\Flow\Enums\BranchOperator;

/**
 * Evaluates a single comparison between a resolved value and an expected value.
 *
 * Shared by every handler that branches on an operand comparison
 * ({@see \App\Domains\Flow\Handlers\BranchNodeHandler},
 * {@see \App\Domains\Flow\Handlers\AuthRequestNodeHandler}) so the operator
 * semantics live in exactly one place.
 */
final class OperatorComparator
{
    /**
     * Whether {@code $value} satisfies {@code $operator} against {@code $expected}.
     * An unknown/empty operator never matches.
     */
    public function matches(mixed $value, ?string $operator, mixed $expected): bool
    {
        $resolved = null !== $operator ? BranchOperator::tryFrom($operator) : null;

        if (null === $resolved) {
            return false;
        }

        return match ($resolved) {
            BranchOperator::Eq       => $value === $expected,
            BranchOperator::Neq      => $value !== $expected,
            BranchOperator::Gt       => $value > $expected,
            BranchOperator::Gte      => $value >= $expected,
            BranchOperator::Lt       => $value < $expected,
            BranchOperator::Lte      => $value <= $expected,
            BranchOperator::Contains => str_contains((string) $value, (string) ($expected ?? '')),
            BranchOperator::In       => in_array($value, is_array($expected) ? $expected : [], true),
            BranchOperator::Empty    => empty($value),
            BranchOperator::NotEmpty => ! empty($value),
        };
    }
}
