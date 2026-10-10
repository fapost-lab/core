<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\Flow\Translations\TranslationScope;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use UnitEnum;

/**
 * Tenant-scoped translations admin page (admin panel). Writes to
 * `tenant_translations`. Single override layer — cells are either an
 * override (warning badge) or the catalog default (no badge).
 */
final class TranslationsPage extends AbstractTranslationsPage
{
    protected static ?string $slug = 'translations';

    protected static ?int $navigationSort = 60;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can(Permission::ManageTranslations->value);
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('media.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('staff.tenant_translations.navigation');
    }

    protected function scope(): TranslationScope
    {
        return TranslationScope::tenant(app(TenantContextInterface::class)->get()->getId());
    }
}
