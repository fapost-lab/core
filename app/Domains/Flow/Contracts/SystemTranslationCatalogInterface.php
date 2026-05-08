<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\Translations\SystemTranslationEntry;

/**
 * Registry of known system translation keys + their built-in defaults.
 *
 * Populated at boot by Core, Features and Solutions — tenants override the
 * default text via tenant_translations but never define new keys.
 *
 * Used by {@see ContentTranslatorInterface} as the last fallback before
 * returning the bare key.
 */
interface SystemTranslationCatalogInterface
{
    public function register(SystemTranslationEntry $entry): void;

    public function find(string $key): ?SystemTranslationEntry;

    /**
     * @return array<string, SystemTranslationEntry>  key → entry, sorted by key
     */
    public function entries(): array;
}
