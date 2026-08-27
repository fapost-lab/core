<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\TenantTranslationRepositoryInterface;
use App\Domains\Flow\Contracts\TenantTranslationServiceInterface;

final readonly class TenantTranslationService implements TenantTranslationServiceInterface
{
    public function __construct(
        private TenantTranslationRepositoryInterface $translations,
        private ContentTranslatorInterface $translator,
    ) {
    }

    public function upsert(string $tenantId, string $key, string $language, string $value): void
    {
        $this->translations->upsert($tenantId, $key, $language, $value);
        $this->translator->invalidate($tenantId, $language);
    }

    public function delete(string $tenantId, string $key, string $language): void
    {
        $this->translations->delete($tenantId, $key, $language);
        $this->translator->invalidate($tenantId, $language);
    }
}
