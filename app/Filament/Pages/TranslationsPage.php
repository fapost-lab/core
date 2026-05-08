<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domains\Flow\Contracts\TenantTranslationRepositoryInterface;
use App\Domains\Flow\Contracts\TenantTranslationServiceInterface;
use App\Domains\Flow\Contracts\TranslationOverrideRepositoryInterface;
use App\Domains\Flow\Contracts\TranslationOverrideServiceInterface;
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

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('media.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('staff.tenant_translations.navigation');
    }

    protected function scopeId(): string
    {
        return app(TenantContextInterface::class)->get()->getId();
    }

    protected function repository(): TranslationOverrideRepositoryInterface
    {
        return app(TenantTranslationRepositoryInterface::class);
    }

    protected function service(): TranslationOverrideServiceInterface
    {
        return app(TenantTranslationServiceInterface::class);
    }

    protected function layers(): array
    {
        return [
            ['matrix' => $this->repository()->matrix($this->scopeId()), 'status' => 'override'],
        ];
    }
}
