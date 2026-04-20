<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\TenantTranslationRepositoryInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Settings\TenantSettings;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;

final readonly class CachedContentTranslator implements ContentTranslatorInterface
{
    public function __construct(
        private TenantTranslationRepositoryInterface $translations,
        private TenantSettings $tenantSettings,
        private CacheRepository $cache,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function translate(string $key, string $language): string
    {
        $tenantId = $this->tenantContext->get()->getId();
        $primary  = $this->getLanguageMap($tenantId, $language);

        if (array_key_exists($key, $primary)) {
            return $primary[$key];
        }

        $baseLanguage = $this->tenantSettings->content_base_language;

        if ($baseLanguage !== $language) {
            $base = $this->getLanguageMap($tenantId, $baseLanguage);

            if (array_key_exists($key, $base)) {
                return $base[$key];
            }
        }

        Log::warning('Tenant translation key not found', [
            'tenant_id' => $tenantId,
            'language'  => $language,
            'key'       => $key,
        ]);

        return $key;
    }

    public function resolveField(array|string $content, string $language): string
    {
        if (is_string($content)) {
            return $content;
        }

        if ([] === $content) {
            return '';
        }

        $baseLanguage = $this->tenantSettings->content_base_language;

        if (isset($content[$language]) && is_scalar($content[$language])) {
            return (string) $content[$language];
        }

        if (isset($content[$baseLanguage]) && is_scalar($content[$baseLanguage])) {
            return (string) $content[$baseLanguage];
        }

        $firstKey = array_key_first($content);

        if (null === $firstKey || ! is_scalar($content[$firstKey])) {
            return '';
        }

        return (string) $content[$firstKey];
    }

    public function invalidate(string $tenantId, string $language): void
    {
        $this->cache->forget($this->cacheKey($tenantId, $language));
    }

    /**
     * @return array<string, string>
     */
    private function getLanguageMap(string $tenantId, string $language): array
    {
        /** @var array<string, string> $translations */
        $translations = $this->cache->rememberForever(
            $this->cacheKey($tenantId, $language),
            fn (): array => $this->translations->getAllForLanguage($tenantId, $language),
        );

        return $translations;
    }

    private function cacheKey(string $tenantId, string $language): string
    {
        return "translations:{$tenantId}:{$language}";
    }
}
