<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Contacts;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Filament\Assistant\Resources\Contacts\Pages\ListContacts;
use App\Filament\Assistant\Resources\Contacts\Pages\ViewContact;
use App\Filament\Assistant\Resources\Contacts\Schemas\ContactInfolistSchema;
use App\Filament\Assistant\Resources\Contacts\Tables\ContactsTable;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only assistant-scoped Contact viewer.
 *
 * Contacts are tenant-level entities reachable from this panel only when they
 * have at least one channel linkage to a channel owned by the current
 * Filament-resolved Assistant. The viewer renders the JSON {@code attributes}
 * column as collapsible Profile/group sections (see
 * {@see ContactInfolistSchema}); no edit/create — group writes belong to the
 * flow runtime, not to operators.
 */
final class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static ?int $navigationSort = 70;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    /**
     * Contact has no direct `assistants` relation (link runs through
     * `channel_contacts → channels.assistant_id`), so the auto-scope from
     * Filament tenancy can't bind. We disable it here and apply the
     * assistant filter manually in {@see getEloquentQuery()}.
     */
    protected static bool $isScopedToTenant = false;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.operations');
    }

    public static function getModelLabel(): string
    {
        return __('contact.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('contact.plural_label');
    }

    public static function table(Table $table): Table
    {
        return ContactsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ContactInfolistSchema::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContacts::route('/'),
            'view'  => ViewContact::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Restrict listing/access to contacts that touched a channel of the current
     * Filament-resolved {@see Assistant}. Contacts have no direct assistant FK;
     * the link runs through {@code channel_contacts → channels.assistant_id}.
     */
    public static function getEloquentQuery(): Builder
    {
        $query  = parent::getEloquentQuery();
        $tenant = Filament::getTenant();

        if ($tenant instanceof Assistant) {
            $query->whereHas(
                'channelContacts.channel',
                static fn (Builder $q): Builder => $q->where('assistant_id', $tenant->getKey()),
            );
        }

        return $query;
    }
}
