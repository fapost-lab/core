<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domains\Flow\Logging\FlowLogStatus;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bar chart showing daily flow node executions (executed vs failed) for the last 14 days.
 */
final class FlowActivityChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '60s';

    protected bool $isCollapsible = true;

    public function getHeading(): ?string
    {
        return __('staff.dashboard.chart.flow_activity.heading');
    }

    protected function getData(): array
    {
        $days  = 14;
        $start = Carbon::now()->subDays($days - 1)->startOfDay();

        $rows = DB::table('flow_logs')
            ->selectRaw("DATE(created_at AT TIME ZONE 'UTC') AS day, status, COUNT(*) AS total")
            ->where('created_at', '>=', $start)
            ->groupByRaw("DATE(created_at AT TIME ZONE 'UTC'), status")
            ->orderBy('day')
            ->get()
            ->groupBy('day');

        $labels   = [];
        $executed = [];
        $failed   = [];

        for ($i = 0; $i < $days; $i++) {
            $date    = Carbon::now()->subDays($days - 1 - $i)->toDateString();
            $dayRows = $rows->get($date, collect());

            $labels[] = Carbon::parse($date)->format('M d');

            $executedCount = 0;
            $failedCount   = 0;

            foreach ($dayRows as $row) {
                match ($row->status) {
                    FlowLogStatus::Executed->value => $executedCount += (int)$row->total,
                    default                        => $failedCount += (int)$row->total,
                };
            }

            $executed[] = $executedCount;
            $failed[]   = $failedCount;
        }

        return [
            'datasets' => [
                [
                    'label'           => __('staff.dashboard.chart.flow_activity.executed'),
                    'data'            => $executed,
                    'backgroundColor' => 'rgba(34, 197, 94, 0.7)',
                    'borderColor'     => 'rgba(34, 197, 94, 1)',
                    'borderWidth'     => 1,
                ],
                [
                    'label'           => __('staff.dashboard.chart.flow_activity.failed'),
                    'data'            => $failed,
                    'backgroundColor' => 'rgba(239, 68, 68, 0.7)',
                    'borderColor'     => 'rgba(239, 68, 68, 1)',
                    'borderWidth'     => 1,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
