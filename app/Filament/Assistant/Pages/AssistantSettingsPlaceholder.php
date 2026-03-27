<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

final class AssistantSettingsPlaceholder extends Page
{
    protected static ?string $slug = 'settings';

    protected static ?int $navigationSort = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.settings');
    }

    public function getTitle(): string
    {
        return __('assistant.pages.settings.title');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Text::make(__('assistant.pages.settings.placeholder')),
                    ]),
            ]);
    }
}
