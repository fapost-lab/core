<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Flow\Contracts\AssistantTranslationRepositoryInterface;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\SystemTranslationCatalogInterface;
use App\Domains\Flow\Contracts\TenantTranslationRepositoryInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Settings\TenantSettings;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class CachedContentTranslator implements ContentTranslatorInterface
{
    public function __construct(
        private TenantTranslationRepositoryInterface $tenantTranslations,
        private AssistantTranslationRepositoryInterface $assistantTranslations,
        private TenantSettings $tenantSettings,
        private CacheRepository $cache,
        private TenantContextInterface $tenantContext,
        private CurrentAssistantInterface $assistantContext,
        private SystemTranslationCatalogInterface $catalog,
    ) {
    }

    /**
     * Resolution chain (first non-empty wins):
     *  1. assistant_translations[key, language]              (if assistant resolved)
     *  2. tenant_translations[key, language]
     *  3. tenant_translations[key, content_base_language]
     *  4. catalog[key, language]
     *  5. catalog[key, fallback='en']
     *  6. key (last-resort, also logged)
     */
    public function translate(string $key, string $language): string
    {
        $tenantId = $this->tenantContext->get()->getId();

        // Assistant override layer — only consulted when an assistant context
        // is resolved (UI sessions, runtime flows). Background/admin tasks
        // without assistant scope skip directly to tenant.
        $assistantId = $this->resolveAssistantId();

        if (null !== $assistantId) {
            $assistantPrimary = $this->getAssistantLanguageMap($assistantId, $language);

            if (array_key_exists($key, $assistantPrimary)) {
                return $assistantPrimary[$key];
            }
        }

        $tenantPrimary = $this->getTenantLanguageMap($tenantId, $language);

        if (array_key_exists($key, $tenantPrimary)) {
            return $tenantPrimary[$key];
        }

        $baseLanguage = $this->tenantSettings->content_base_language;

        if ($baseLanguage !== $language) {
            $base = $this->getTenantLanguageMap($tenantId, $baseLanguage);

            if (array_key_exists($key, $base)) {
                return $base[$key];
            }
        }

        $catalogEntry = $this->catalog->find($key);

        if (null !== $catalogEntry) {
            $default = $catalogEntry->default($language);

            if (null !== $default) {
                return $default;
            }
        }

        Log::warning('Tenant translation key not found', [
            'tenant_id'    => $tenantId,
            'assistant_id' => $assistantId,
            'language'     => $language,
            'key'          => $key,
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
        $this->cache->forget($this->tenantCacheKey($tenantId, $language));
    }

    public function invalidateAssistant(string $assistantId, string $language): void
    {
        $this->cache->forget($this->assistantCacheKey($assistantId, $language));
    }

    /**
     * @return array<string, string>
     */
    private function getTenantLanguageMap(string $tenantId, string $language): array
    {
        /** @var array<string, string> $translations */
        $translations = $this->cache->rememberForever(
            $this->tenantCacheKey($tenantId, $language),
            fn (): array => $this->tenantTranslations->getAllForLanguage($tenantId, $language),
        );

        return $translations;
    }

    /**
     * @return array<string, string>
     */
    private function getAssistantLanguageMap(string $assistantId, string $language): array
    {
        /** @var array<string, string> $translations */
        $translations = $this->cache->rememberForever(
            $this->assistantCacheKey($assistantId, $language),
            fn (): array => $this->assistantTranslations->getAllForLanguage($assistantId, $language),
        );

        return $translations;
    }

    private function tenantCacheKey(string $tenantId, string $language): string
    {
        return "translations:tenant:{$tenantId}:{$language}";
    }

    private function assistantCacheKey(string $assistantId, string $language): string
    {
        return "translations:assistant:{$assistantId}:{$language}";
    }

    /**
     * Pull the assistant id from the scoped context if one is resolved.
     * Wrapped in a try/catch because some contexts (e.g. queue jobs not
     * tied to an assistant) intentionally leave the binding empty — that
     * is an expected condition, not an error.
     */
    private function resolveAssistantId(): ?string
    {
        try {
            if (! $this->assistantContext->isResolved()) {
                return null;
            }

            return (string) $this->assistantContext->get()->getKey();
        } catch (Throwable) {
            return null;
        }
    }
}
