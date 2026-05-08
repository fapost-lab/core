<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Pages;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Flow\Contracts\AssistantTranslationRepositoryInterface;
use App\Domains\Flow\Contracts\AssistantTranslationServiceInterface;
use App\Domains\Flow\Contracts\TenantTranslationRepositoryInterface;
use App\Domains\Flow\Contracts\TranslationOverrideRepositoryInterface;
use App\Domains\Flow\Contracts\TranslationOverrideServiceInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Filament\Pages\AbstractTranslationsPage;
use UnitEnum;

/**
 * Assistant-scoped translations page (assistant panel). Writes to
 * `assistant_translations`; cells render in three states:
 *  - assistant override (warning badge)
 *  - inherited from tenant override (info badge)
 *  - catalog default (no badge)
 */
final class TranslationsPage extends AbstractTranslationsPage
{
    protected static ?string $slug = 'translations';

    protected static ?int $navigationSort = 70;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('staff.tenant_translations.navigation');
    }

    protected function scopeId(): string
    {
        return (string) app(CurrentAssistantInterface::class)->get()->getKey();
    }

    protected function repository(): TranslationOverrideRepositoryInterface
    {
        return app(AssistantTranslationRepositoryInterface::class);
    }

    protected function service(): TranslationOverrideServiceInterface
    {
        return app(AssistantTranslationServiceInterface::class);
    }

    /**
     * Two layers: the assistant's own overrides win first; tenant overrides
     * surface as "inherited" so editors can see what the upper layer would
     * have shown without committing an assistant-level row.
     */
    protected function layers(): array
    {
        $tenantId = app(TenantContextInterface::class)->get()->getId();

        return [
            [
                'matrix' => $this->repository()->matrix($this->scopeId()),
                'status' => 'override',
            ],
            [
                'matrix' => app(TenantTranslationRepositoryInterface::class)->matrix($tenantId),
                'status' => 'inherited',
            ],
        ];
    }
}
