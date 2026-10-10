<?php

declare(strict_types=1);

namespace App\Domains\Flow\Translations;

/**
 * Which override layer a translations screen edits: the tenant's own (`tenant_translations`), or one assistant's
 * (`assistant_translations`), under which the tenant's overrides still show as inherited.
 */
final readonly class TranslationScope
{
    private function __construct(
        public string $tenantId,
        public ?string $assistantId,
    ) {
    }

    public static function tenant(string $tenantId): self
    {
        return new self($tenantId, null);
    }

    public static function assistant(string $tenantId, string $assistantId): self
    {
        return new self($tenantId, $assistantId);
    }

    public function isAssistant(): bool
    {
        return null !== $this->assistantId;
    }
}
