<?php

declare(strict_types=1);

namespace App\Domains\Flow\Translations;

/**
 * Catalog entry for a known system translation key. Each entry carries the
 * key identifier, its logical group (used for UI sectioning), a human
 * description, and a map of language → default text used as the last
 * resolution fallback before returning the bare key.
 *
 * Defaults are authored in code (Core / Features / Solutions register their
 * own) — tenants only override them, never invent new keys.
 */
final readonly class SystemTranslationEntry
{
    /**
     * @param  array<string, string>  $defaults  language code → default text
     */
    public function __construct(
        public string $key,
        public string $group,
        public string $description,
        public array $defaults,
    ) {
    }

    public function default(string $language, string $fallbackLanguage = 'en'): ?string
    {
        if (isset($this->defaults[$language])) {
            return $this->defaults[$language];
        }

        return $this->defaults[$fallbackLanguage] ?? null;
    }
}
