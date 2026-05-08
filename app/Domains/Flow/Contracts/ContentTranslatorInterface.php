<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

interface ContentTranslatorInterface
{
    public function translate(string $key, string $language): string;

    public function resolveField(array|string $content, string $language): string;

    /**
     * Drop the cached tenant-scoped overrides for `(tenantId, language)`.
     */
    public function invalidate(string $tenantId, string $language): void;

    /**
     * Drop the cached assistant-scoped overrides for `(assistantId, language)`.
     * Tenant cache is untouched — the next translate() rebuilds the assistant
     * layer only.
     */
    public function invalidateAssistant(string $assistantId, string $language): void;
}
