<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

/**
 * Override storage backing the {@see ContentTranslatorInterface} fallback chain.
 *
 * Both tenant-level and assistant-level overrides implement this contract:
 * the only differences across implementations are the table name and what
 * `$scopeId` denotes (tenant_id / assistant_id). The translator picks the
 * right repository per layer of the chain.
 */
interface TranslationOverrideRepositoryInterface
{
    /**
     * @return array<string, string>  key → value, for the given scope+language
     */
    public function getAllForLanguage(string $scopeId, string $language): array;

    public function upsert(string $scopeId, string $key, string $language, string $value): void;

    public function delete(string $scopeId, string $key, string $language): void;

    /**
     * Whole override matrix for the scope — used by admin pages to render
     * one row per catalog key with a column per language without N queries.
     *
     * @return array<string, array<string, string>>  key → language → value
     */
    public function matrix(string $scopeId): array;
}
