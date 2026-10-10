<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\AssistantTranslationRepositoryInterface;
use App\Domains\Flow\Contracts\AssistantTranslationServiceInterface;
use App\Domains\Flow\Contracts\SystemTranslationCatalogInterface;
use App\Domains\Flow\Contracts\TenantTranslationRepositoryInterface;
use App\Domains\Flow\Contracts\TenantTranslationServiceInterface;
use App\Domains\Flow\Contracts\TranslationOverrideRepositoryInterface;
use App\Domains\Flow\Contracts\TranslationOverrideServiceInterface;
use App\Domains\Flow\Translations\SystemTranslationEntry;
use App\Domains\Flow\Translations\TranslationScope;
use App\Domains\Tenancy\Settings\TenantSettings;

/**
 * The translations screens of both panels: one row per system catalog key, one cell per platform language, and the
 * writes that set or clear the overrides of one layer.
 *
 * A cell shows the first layer that has a value, top-down: the scope's own override (`override`), then, for an
 * assistant, the tenant's override (`inherited`), then the catalog default (`default`). Writes go through the layer's
 * write service, which also drops the translator's cache, and only ever touch the scope's own layer: an assistant
 * screen never changes the tenant's overrides.
 *
 * Resolve it per request: it holds the current tenant's settings.
 */
final readonly class TranslationOverrideEditor
{
    public const string STATUS_OVERRIDE = 'override';

    public const string STATUS_INHERITED = 'inherited';

    public const string STATUS_DEFAULT = 'default';

    public function __construct(
        private SystemTranslationCatalogInterface $catalog,
        private TenantTranslationRepositoryInterface $tenantOverrides,
        private AssistantTranslationRepositoryInterface $assistantOverrides,
        private TenantTranslationServiceInterface $tenantWriter,
        private AssistantTranslationServiceInterface $assistantWriter,
        private TenantSettings $settings,
    ) {
    }

    /**
     * The tenant's languages, `en` first when the tenant has not listed it: the catalog falls back to it last.
     *
     * @return list<string>
     */
    public function languages(): array
    {
        $configured = $this->settings->available_languages;

        if (! in_array('en', $configured, true)) {
            $configured = array_merge(['en'], $configured);
        }

        return array_values(array_unique(array_map(strval(...), $configured)));
    }

    public function entry(string $key): ?SystemTranslationEntry
    {
        return $this->catalog->find($key);
    }

    /**
     * The catalog's groups, in order of first appearance by key.
     *
     * @return list<string>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->catalog->entries() as $entry) {
            $groups[$entry->group] = true;
        }

        return array_keys($groups);
    }

    /**
     * Every catalog key with its cells, in the catalog's order (by key). `override` is the scope's own value ('' when
     * it has none), what the edit form starts from; `inherited` is the tenant's override under an assistant (null when
     * there is none, always null for the tenant's own scope); `default` is the catalog's text for the language.
     *
     * @return list<array{
     *     key: string,
     *     group: string,
     *     description: string,
     *     hasOverride: bool,
     *     languages: list<array{language: string, value: string, status: string, override: string, inherited: string|null, default: string}>
     * }>
     */
    public function rows(TranslationScope $scope, string $descriptionLocale): array
    {
        $languages = $this->languages();
        $own       = $this->repository($scope)->matrix($this->scopeId($scope));
        $inherited = $scope->isAssistant() ? $this->tenantOverrides->matrix($scope->tenantId) : [];

        $rows = [];

        foreach ($this->catalog->entries() as $entry) {
            $cells       = [];
            $hasOverride = false;

            foreach ($languages as $language) {
                $default  = $entry->default($language) ?? '';
                $override = $own[$entry->key][$language] ?? null;
                $parent   = $inherited[$entry->key][$language] ?? null;

                [$value, $status] = match (true) {
                    null !== $override => [$override, self::STATUS_OVERRIDE],
                    null !== $parent   => [$parent, self::STATUS_INHERITED],
                    default            => [$default, self::STATUS_DEFAULT],
                };

                $hasOverride = $hasOverride || null !== $override;
                $cells[]     = [
                    'language'  => $language,
                    'value'     => $value,
                    'status'    => $status,
                    'override'  => $override ?? '',
                    'inherited' => $parent,
                    'default'   => $default,
                ];
            }

            $rows[] = [
                'key'         => $entry->key,
                'group'       => $entry->group,
                'description' => $entry->getDescription($descriptionLocale),
                'hasOverride' => $hasOverride,
                'languages'   => $cells,
            ];
        }

        return $rows;
    }

    /**
     * The scope's own overrides of one key, by language ('' where it has none).
     *
     * @return array<string, string>
     */
    public function overrides(TranslationScope $scope, string $key): array
    {
        $matrix = $this->repository($scope)->matrix($this->scopeId($scope));
        $values = [];

        foreach ($this->languages() as $language) {
            $values[$language] = $matrix[$key][$language] ?? '';
        }

        return $values;
    }

    /**
     * Sets the key's overrides of the scope for every tenant language: a value is trimmed and stored, an empty or
     * missing one removes the override, so the cell falls back to the next layer. Languages outside the tenant's are
     * ignored.
     *
     * @param  array<string, string|null>  $values  language → text
     */
    public function save(TranslationScope $scope, string $key, array $values): void
    {
        $writer = $this->writer($scope);
        $id     = $this->scopeId($scope);

        foreach ($this->languages() as $language) {
            $value = mb_trim((string) ($values[$language] ?? ''));

            if ('' === $value) {
                $writer->delete($id, $key, $language);
            } else {
                $writer->upsert($id, $key, $language, $value);
            }
        }
    }

    /**
     * Removes the scope's overrides of the key in every tenant language.
     */
    public function reset(TranslationScope $scope, string $key): void
    {
        $writer = $this->writer($scope);
        $id     = $this->scopeId($scope);

        foreach ($this->languages() as $language) {
            $writer->delete($id, $key, $language);
        }
    }

    private function scopeId(TranslationScope $scope): string
    {
        return $scope->assistantId ?? $scope->tenantId;
    }

    private function repository(TranslationScope $scope): TranslationOverrideRepositoryInterface
    {
        return $scope->isAssistant() ? $this->assistantOverrides : $this->tenantOverrides;
    }

    private function writer(TranslationScope $scope): TranslationOverrideServiceInterface
    {
        return $scope->isAssistant() ? $this->assistantWriter : $this->tenantWriter;
    }
}
