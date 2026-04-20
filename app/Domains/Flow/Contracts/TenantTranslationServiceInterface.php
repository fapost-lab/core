<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

interface TenantTranslationServiceInterface
{
    public function upsert(string $tenantId, string $key, string $language, string $value): void;
}
