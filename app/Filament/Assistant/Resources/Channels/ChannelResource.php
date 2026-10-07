<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Channels;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Filament\Assistant\Resources\Channels\Pages\CreateChannel;
use App\Filament\Assistant\Resources\Channels\Pages\EditChannel;
use App\Filament\Assistant\Resources\Channels\Pages\ListChannels;
use App\Filament\Assistant\Resources\Channels\Tables\ChannelsTable;
use App\Filament\Resources\Assistants\Schemas\ChannelFormSchema;
use App\Filament\Support\RecordLimit;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

final class ChannelResource extends Resource
{
    protected static ?string $model = Channel::class;

    protected static ?string $tenantOwnershipRelationshipName = 'assistant';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.channels');
    }

    public static function getModelLabel(): string
    {
        return __('staff.channels.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('staff.channels.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return ChannelFormSchema::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChannelsTable::configureColumns($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListChannels::route('/'),
            'create' => CreateChannel::route('/create'),
            'edit'   => EditChannel::route('/{record}/edit'),
        ];
    }

    /**
     * Closed for everyone, admins included, once the tenant is at its limit.
     * The policy cannot do this: admins pass `Gate::before`.
     */
    public static function canCreate(): bool
    {
        return static::canCreateIgnoringLimit() && ! self::isLimitReached();
    }

    /**
     * The policy answer alone: a create page checks it on every request, the limit only on mount.
     */
    public static function canCreateIgnoringLimit(): bool
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Assistant) {
            return false;
        }

        return Gate::allows('create', [Channel::class, $tenant]);
    }

    /**
     * The tenant's limit as it stands now: whether it is reached and the "N of M" hint.
     */
    public static function limit(): RecordLimit
    {
        return new RecordLimit(Channel::LIMIT_KEY, Channel::countForLimit(), 'staff.channels.limit');
    }

    public static function isLimitReached(): bool
    {
        return self::limit()->reached;
    }
}
