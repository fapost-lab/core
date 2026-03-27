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

final class AssistantFlowPlaceholder extends Page
{
    protected static ?string $slug = 'flow';

    protected static ?int $navigationSort = 40;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.flow');
    }

    public function getTitle(): string
    {
        return __('assistant.pages.flow.title');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Text::make(__('assistant.pages.flow.placeholder')),
                    ]),
            ]);
    }
}
