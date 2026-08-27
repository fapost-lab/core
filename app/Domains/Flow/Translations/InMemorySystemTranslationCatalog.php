<?php

declare(strict_types=1);

namespace App\Domains\Flow\Translations;

use App\Domains\Flow\Contracts\SystemTranslationCatalogInterface;
use LogicException;

/**
 * In-memory catalog populated at boot. Single instance per app lifecycle:
 * registered entries are immutable for the rest of the request.
 *
 * Singleton-bound — long-lived workers reuse the same instance across jobs,
 * which is correct because the catalog is global platform-level metadata.
 */
final class InMemorySystemTranslationCatalog implements SystemTranslationCatalogInterface
{
    /** @var array<string, SystemTranslationEntry> */
    private array $entries = [];

    public function register(SystemTranslationEntry $entry): void
    {
        if (isset($this->entries[$entry->key])) {
            throw new LogicException(sprintf(
                'System translation key "%s" already registered. Catalog keys are global and must be unique.',
                $entry->key,
            ));
        }

        $this->entries[$entry->key] = $entry;
    }

    public function find(string $key): ?SystemTranslationEntry
    {
        return $this->entries[$key] ?? null;
    }

    public function entries(): array
    {
        $sorted = $this->entries;
        ksort($sorted);

        return $sorted;
    }
}
