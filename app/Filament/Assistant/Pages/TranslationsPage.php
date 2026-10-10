<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Pages;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Flow\Translations\TranslationScope;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
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

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can(Permission::ManageTranslations->value);
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('staff.tenant_translations.navigation');
    }

    /**
     * The assistant's own overrides win first; tenant overrides surface as "inherited" so editors can see what the
     * upper layer would have shown without committing an assistant-level row.
     */
    protected function scope(): TranslationScope
    {
        return TranslationScope::assistant(
            app(TenantContextInterface::class)->get()->getId(),
            (string) app(CurrentAssistantInterface::class)->get()->getKey(),
        );
    }
}
