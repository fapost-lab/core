<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domains\Flow\Logging\FlowLogStatus;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

        // `app.timezone` is UTC (see config/app.php) and `flow_logs.created_at` is
        // stored in UTC on every driver, so the calendar days cut here line up with
        // the UTC day buckets built in PHP below. If `app.timezone` ever stops being
        // UTC, this query and the bucketing loop must be revisited together.
        //
        // The PostgreSQL branch converts explicitly: `created_at` is a `timestamptz`
        // and `TO_CHAR` would otherwise render it in the session's TimeZone, which an
        // installation is free to set to anything, silently shifting every day
        // boundary. SQLite stores the app timezone already and has no such setting.
        $day = match ($driver = DB::connection()->getDriverName()) {
            'pgsql'  => "TO_CHAR(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD')",
            'sqlite' => "strftime('%Y-%m-%d', created_at)",
            default  => throw new RuntimeException("FlowActivityChart: unsupported database driver [{$driver}]."),
        };

        $rows = DB::table('flow_logs')
            ->selectRaw("{$day} AS day, status, COUNT(*) AS total")
            ->where('created_at', '>=', $start)
            ->groupByRaw("{$day}, status")
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
                // Exhaustive on purpose: a new FlowLogStatus case must not
                // silently fall into either bucket.
                match (FlowLogStatus::from($row->status)) {
                    // `terminal` is a normal flow completion (see FlowEngine::
                    // buildLogEntry), not a failure, so it counts as executed
                    // alongside `executed` itself.
                    FlowLogStatus::Executed, FlowLogStatus::Terminal => $executedCount += (int)$row->total,
                    FlowLogStatus::Failed                            => $failedCount += (int)$row->total,
                    // `conflict` is a defined status (see the `flow_logs` status
                    // CHECK constraint) that FlowEngine never writes today. There
                    // is no observed case to decide "executed" or "failed" from,
                    // so it is deliberately counted in neither rather than
                    // guessed at.
                    FlowLogStatus::Conflict => null,
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
