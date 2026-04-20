<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

interface TenantTranslationRepositoryInterface
{
    /**
     * @return array<string, string>
     */
    public function getAllForLanguage(string $tenantId, string $language): array;

    public function upsert(string $tenantId, string $key, string $language, string $value): void;
}
