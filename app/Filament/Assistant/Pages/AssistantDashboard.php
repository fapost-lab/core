<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Pages;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Filament\Assistant\Resources\FlowLogs\FlowLogResource;
use App\Filament\Assistant\Resources\FlowSessions\FlowSessionResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Explicit home dashboard for the assistant Filament panel (operational UI only; CRUD stays on admin
 * {@see \App\Filament\Resources\Assistants\AssistantResource}).
 */
final class AssistantDashboard extends Page
{
    protected static ?string            $slug               = 'dashboard';
    protected static ?int               $navigationSort     = -100;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;
    protected CurrentAssistantInterface $currentAssistant;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('assistant.navigation.groups.overview');
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
                Section::make(__('assistant.navigation.groups.operations'))
                    ->footerActions([
                        // URL closures evaluate at render time — after Filament has
                        // fully discovered every resource and registered routes.
                        // Using literal calls here breaks under stale route cache.
                        Action::make('open_sessions')
                            ->label(__('assistant.pages.overview.operations.live_sessions', [
                                'count' => $this->liveSessionsCount($assistant->getKey()),
                            ]))
                            ->icon(Heroicon::OutlinedQueueList)
                            ->url(fn (): string => FlowSessionResource::getUrl('index', tenant: $assistant)),
                        Action::make('open_logs')
                            ->label(__('assistant.pages.overview.operations.errors_24h', [
                                'count' => $this->failedLogsLast24hCount($assistant->getKey()),
                            ]))
                            ->icon(Heroicon::OutlinedDocumentText)
                            ->url(fn (): string => FlowLogResource::getUrl('index', tenant: $assistant)),
                    ])
                    ->schema([
                        Text::make(__('assistant.pages.overview.operations.intro')),
                    ]),
                Section::make(__('assistant.navigation.groups.settings'))
                    ->schema([
                        Text::make(__('assistant.pages.overview.placeholders.settings')),
                    ]),
            ]);
    }

    public function boot(CurrentAssistantInterface $currentAssistant): void
    {
        $this->currentAssistant = $currentAssistant;
    }

    private function liveSessionsCount(string $assistantId): int
    {
        return FlowSession::query()
            ->where('assistant_id', $assistantId)
            ->whereIn('status', [
                FlowSessionStatus::Active->value,
                FlowSessionStatus::WaitingInput->value,
                FlowSessionStatus::PausedSubflow->value,
            ])
            ->count();
    }

    private function failedLogsLast24hCount(string $assistantId): int
    {
        return FlowLog::query()
            ->whereNotNull('error')
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->whereHas('session', static fn ($q) => $q->where('assistant_id', $assistantId))
            ->count();
    }
}
