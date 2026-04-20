<?php

declare(strict_types=1);

namespace App\Domains\Flow\Repositories;

use App\Domains\Flow\Contracts\TenantTranslationRepositoryInterface;
use App\Domains\Flow\Models\TenantTranslation;
use Illuminate\Database\UniqueConstraintViolationException;

final class TenantTranslationRepository implements TenantTranslationRepositoryInterface
{
    public function getAllForLanguage(string $tenantId, string $language): array
    {
        /** @var array<string, string> $pairs */
        $pairs = TenantTranslation::query()
            ->where('tenant_id', $tenantId)
            ->where('language', $language)
            ->pluck('value', 'key')
            ->all();

        return $pairs;
    }

    public function upsert(string $tenantId, string $key, string $language, string $value): void
    {
        try {
            TenantTranslation::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'key'       => $key,
                    'language'  => $language,
                ],
                ['value' => $value],
            );
        } catch (UniqueConstraintViolationException) {
            TenantTranslation::query()
                ->where('tenant_id', $tenantId)
                ->where('key', $key)
                ->where('language', $language)
                ->update(['value' => $value]);
        }
    }
}
