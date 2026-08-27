<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

/**
 * Write-side service for an override layer (tenant or assistant): wraps the
 * repository with cache-invalidation so admin UIs don't have to know about
 * the translator cache.
 */
interface TranslationOverrideServiceInterface
{
    public function upsert(string $scopeId, string $key, string $language, string $value): void;

    /**
     * Removes the override at this scope; the translator falls back to the
     * next layer (parent scope or catalog) on the next request.
     */
    public function delete(string $scopeId, string $key, string $language): void;
}
