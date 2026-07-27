<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\ContactSegments;

use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Filament\Assistant\Resources\ContactSegments\Pages\CreateContactSegment;
use App\Filament\Assistant\Resources\ContactSegments\Pages\EditContactSegment;
use App\Filament\Assistant\Resources\ContactSegments\Pages\ListContactSegments;
use App\Filament\Assistant\Resources\ContactSegments\Schemas\ContactSegmentFormSchema;
use App\Filament\Assistant\Resources\ContactSegments\Tables\ContactSegmentsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Saved audience filters over tenant contacts. Segments are tenant-level (they
 * filter on contact attributes, not assistant reachability), so scoping is by
 * tenant, not by the Filament assistant tenant.
 */
final class ContactSegmentResource extends Resource
{
    protected static ?string $model = ContactSegment::class;

    protected static bool $isScopedToTenant = false;

    protected static ?int $navigationSort = 78;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.operations');
    }

    public static function getModelLabel(): string
    {
        return __('segment.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('segment.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return ContactSegmentFormSchema::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContactSegmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListContactSegments::route('/'),
            'create' => CreateContactSegment::route('/create'),
            'edit'   => EditContactSegment::route('/{record}/edit'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user() instanceof User;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', app(TenantContextInterface::class)->get()->getId());
    }
}
