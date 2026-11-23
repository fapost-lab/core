<?php

declare(strict_types=1);

namespace App\Domains\Flow\Admission;

/**
 * Whether a trigger's new run may start; a refusal says why.
 */
final readonly class AdmissionDecision
{
    private function __construct(
        public ?RefusalReason $refusal,
    ) {
    }

    public static function allowed(): self
    {
        return new self(null);
    }

    public static function refused(RefusalReason $reason): self
    {
        return new self($reason);
    }

    public function isAllowed(): bool
    {
        return null === $this->refusal;
    }
}
