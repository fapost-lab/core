<?php

declare(strict_types=1);

namespace App\Domains\Flow\Repositories;

use App\Domains\Flow\Contracts\AssistantTranslationRepositoryInterface;
use App\Domains\Flow\Models\AssistantTranslation;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Storage layer for assistant-scoped translation overrides. Mirrors
 * {@see TenantTranslationRepository} — the only differences are the table
 * (`assistant_translations`) and the scope column (`assistant_id`).
 */
final class AssistantTranslationRepository implements AssistantTranslationRepositoryInterface
{
    public function getAllForLanguage(string $scopeId, string $language): array
    {
        /** @var array<string, string> $pairs */
        $pairs = AssistantTranslation::query()
            ->where('assistant_id', $scopeId)
            ->where('language', $language)
            ->pluck('value', 'key')
            ->all();

        return $pairs;
    }

    public function upsert(string $scopeId, string $key, string $language, string $value): void
    {
        try {
            AssistantTranslation::query()->updateOrCreate(
                [
                    'assistant_id' => $scopeId,
                    'key'          => $key,
                    'language'     => $language,
                ],
                ['value' => $value],
            );
        } catch (UniqueConstraintViolationException) {
            AssistantTranslation::query()
                ->where('assistant_id', $scopeId)
                ->where('key', $key)
                ->where('language', $language)
                ->update(['value' => $value]);
        }
    }

    public function delete(string $scopeId, string $key, string $language): void
    {
        AssistantTranslation::query()
            ->where('assistant_id', $scopeId)
            ->where('key', $key)
            ->where('language', $language)
            ->delete();
    }

    public function matrix(string $scopeId): array
    {
        $matrix = [];

        AssistantTranslation::query()
            ->where('assistant_id', $scopeId)
            ->select(['key', 'language', 'value'])
            ->cursor()
            ->each(static function ($row) use (&$matrix): void {
                $matrix[(string) $row->key][(string) $row->language] = (string) $row->value;
            });

        return $matrix;
    }
}
