<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\ContactGroups;

use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Filament\Assistant\Resources\ContactGroups\Pages\CreateContactGroup;
use App\Filament\Assistant\Resources\ContactGroups\Pages\EditContactGroup;
use App\Filament\Assistant\Resources\ContactGroups\Pages\ListContactGroups;
use App\Filament\Assistant\Resources\ContactGroups\Schemas\ContactGroupFormSchema;
use App\Filament\Assistant\Resources\ContactGroups\Tables\ContactGroupsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Named audience lists for targeting broadcasts. Groups are tenant-level, not
 * assistant-level (same reasoning as {@see \App\Filament\Assistant\Resources\ContactSegments\ContactSegmentResource}).
 */
final class ContactGroupResource extends Resource
{
    protected static ?string $model = ContactGroup::class;

    protected static bool $isScopedToTenant = false;

    protected static ?int $navigationSort = 72;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.operations');
    }

    public static function getModelLabel(): string
    {
        return __('contact_group.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('contact_group.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return ContactGroupFormSchema::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContactGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListContactGroups::route('/'),
            'create' => CreateContactGroup::route('/create'),
            'edit'   => EditContactGroup::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('viewAny', ContactGroup::class);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', app(TenantContextInterface::class)->get()->getId());
    }
}
