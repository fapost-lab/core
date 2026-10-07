<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Staff\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Platform-wide operational stats displayed on the admin dashboard.
 */
final class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $totalAssistants  = Assistant::count();
        $activeAssistants = Assistant::where('is_active', true)->count();

        $totalContacts  = Contact::count();
        $activeChannels = Channel::where('is_active', true)->count();
        $totalChannels  = Channel::count();

        $publishedFlows = FlowDefinition::count();

        $waitingSessions = FlowSession::whereIn('status', [
            FlowSessionStatus::WaitingInput,
            FlowSessionStatus::Paused,
        ])->count();

        $staffUsers = User::query()->withoutPlatformSupport()->count();

        return [
            Stat::make(__('staff.dashboard.stats.assistants.label'), $totalAssistants)
                ->description(__('staff.dashboard.stats.assistants.description', ['count' => $activeAssistants]))
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color($activeAssistants > 0 ? 'success' : 'gray'),

            Stat::make(__('staff.dashboard.stats.contacts.label'), number_format($totalContacts))
                ->description(__('staff.dashboard.stats.contacts.description'))
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make(__('staff.dashboard.stats.channels.label'), $activeChannels)
                ->description(
                    __('staff.dashboard.stats.channels.description', [
                        'total'  => $totalChannels,
                        'active' => $activeChannels,
                    ])
                )
                ->descriptionIcon('heroicon-m-signal')
                ->color($activeChannels > 0 ? 'success' : 'warning'),

            Stat::make(__('staff.dashboard.stats.published_flows.label'), $publishedFlows)
                ->description(__('staff.dashboard.stats.published_flows.description'))
                ->descriptionIcon('heroicon-m-arrows-right-left')
                ->color('info'),

            Stat::make(__('staff.dashboard.stats.waiting_sessions.label'), $waitingSessions)
                ->description(__('staff.dashboard.stats.waiting_sessions.description'))
                ->descriptionIcon('heroicon-m-clock')
                ->color($waitingSessions > 0 ? 'warning' : 'gray'),

            Stat::make(__('staff.dashboard.stats.staff_users.label'), $staffUsers)
                ->description(__('staff.dashboard.stats.staff_users.description'))
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('gray'),
        ];
    }
}
