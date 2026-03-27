<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Pages;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Explicit home dashboard for the assistant Filament panel (operational UI only; CRUD stays on admin {@see \App\Filament\Resources\Assistants\AssistantResource}).
 */
final class AssistantDashboard extends Page
{
    protected CurrentAssistantInterface $currentAssistant;

    protected static ?string $slug = 'dashboard';

    protected static ?int $navigationSort = -100;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.overview');
    }

    public function boot(CurrentAssistantInterface $currentAssistant): void
    {
        $this->currentAssistant = $currentAssistant;
    }

    public function getTitle(): string
    {
        return __('assistant.pages.overview.title');
    }

    public function content(Schema $schema): Schema
    {
        $assistant = $this->currentAssistant->get();

        $channelsCount = $assistant->channels()->count();

        return $schema
            ->components([
                Section::make(__('assistant.pages.overview.sections.summary'))
                    ->schema([
                        Text::make(fn (): string => __('assistant.pages.overview.fields.name', [
                            'name' => $assistant->name,
                        ])),
                        Text::make(fn (): string => __('assistant.pages.overview.fields.status', [
                            'active' => $assistant->is_active
                                ? __('assistant.pages.overview.status_active')
                                : __('assistant.pages.overview.status_inactive'),
                        ])),
                    ]),
                Section::make(__('assistant.navigation.groups.channels'))
                    ->schema([
                        Text::make(__('assistant.pages.overview.channels_intro', [
                            'count' => $channelsCount,
                        ])),
                    ]),
                Section::make(__('assistant.navigation.groups.flow'))
                    ->schema([
                        Text::make(__('assistant.pages.overview.placeholders.flow')),
                    ]),
                Section::make(__('assistant.navigation.groups.settings'))
                    ->schema([
                        Text::make(__('assistant.pages.overview.placeholders.settings')),
                    ]),
            ]);
    }
}
