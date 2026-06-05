<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * Comparison operator for a single rule inside a Branch (condition) node.
 * Used both by the config-schema dropdown and the rule evaluator in
 * {@see \App\Domains\Flow\Handlers\BranchNodeHandler::matchesRule()}.
 */
enum BranchOperator: string
{
    case Eq       = 'eq';
    case Neq      = 'neq';
    case Gt       = 'gt';
    case Gte      = 'gte';
    case Lt       = 'lt';
    case Lte      = 'lte';
    case Contains = 'contains';
    case In       = 'in';
    case Empty    = 'empty';
    case NotEmpty = 'not_empty';

    /**
     * Builder dropdown options: value → localized label (admin-UI locale).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = (string) __("builder.operators.{$case->value}");
        }

        return $options;
    }

    /** Operators that take no right-hand value (unary). */
    public function isUnary(): bool
    {
        return self::Empty === $this || self::NotEmpty === $this;
    }
}
