<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

/**
 * Outcome of validating an incoming user input against a node's expected type
 * and rules.
 *
 * On success the {@see $value} carries the parsed/normalized payload that
 * should be written to the configured variable (e.g. float for `number`,
 * E.164 string for `phone`, structured array for `contact`/`location`).
 * On failure {@see $errorKey} identifies the user-facing reason — handlers
 * surface it through metadata for analytics / debugging; the user-facing
 * `on_invalid_message` is rendered separately by the InputNodeHandler.
 */
final readonly class ValidationResult
{
    private function __construct(
        public bool $valid,
        public mixed $value,
        public ?string $errorKey,
    ) {
    }

    public static function ok(mixed $value): self
    {
        return new self(true, $value, null);
    }

    public static function fail(string $errorKey): self
    {
        return new self(false, null, $errorKey);
    }
}
