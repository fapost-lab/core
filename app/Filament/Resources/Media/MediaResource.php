<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media;

use App\Domains\Media\Models\MediaFile;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Filament\Resources\Media\Pages\ListMedia;
use App\Filament\Resources\Media\Pages\ViewMediaFile;
use App\Filament\Resources\Media\Tables\MediaFilesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Admin panel resource that exposes the per-tenant media library.
 *
 * The page hands off all writes to {@see \App\Domains\Media\Contracts\MediaServiceInterface}
 * — Filament does not bypass the service layer (see Notion task 29.4a).
 */
final class MediaResource extends Resource
{
    protected static ?string $model = MediaFile::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    public static function getNavigationGroup(): ?string
    {
        return __('media.navigation_group');
    }

    public static function getModelLabel(): string
    {
        return __('media.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('media.plural_model_label');
    }

    public static function table(Table $table): Table
    {
        return MediaFilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMedia::route('/'),
            'view'  => ViewMediaFile::route('/{record}'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof User
               && (
                   $user->can(Permission::ViewMedia->value)
                   || $user->can(Permission::ManageMedia->value)
               );
    }

    /**
     * @return Builder<MediaFile>
     */
    public static function getEloquentQuery(): Builder
    {
        $tenantId = app(TenantContextInterface::class)->get()->getId();

        return parent::getEloquentQuery()
            ->with('blob')
            ->where('tenant_id', $tenantId);
    }
}
