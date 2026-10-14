<?php

declare(strict_types=1);

namespace App\Domains\Solution\DTO;

/**
 * One thing wrong with an installed Solution package.
 */
final readonly class SolutionViolation
{
    /**
     * @param  string  $package  composer package name; several names joined by ", " when the problem spans packages
     * @param  string  $field  path in the package's composer.json
     */
    public function __construct(
        public string $package,
        public string $field,
        public string $message,
    ) {
    }

    /**
     * "<package>: <field>: <message>", the line an operator reads.
     */
    public function line(): string
    {
        return sprintf('%s: %s: %s', $this->package, $this->field, $this->message);
    }
}
