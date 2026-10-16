<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Staff\Support\LimitNoticeFormat;
use App\Domains\Tenancy\Enums\UsageState;
use App\Domains\Tenancy\ValueObjects\TenantUsageView;
use App\Domains\Tenancy\ValueObjects\UsageRow;
use Carbon\CarbonImmutable;

/**
 * Turns the tenant's {@see TenantUsageView} into the props of the usage page and of the dashboard widget:
 * names in the person's language, numbers written for people (bytes as KB or GB), the end of the period as a date.
 *
 * The state and the percent are already decided by `TenantUsageOverview`; the browser only draws them.
 */
final class UsagePresenter
{
    /**
     * How many limits the dashboard widget lists.
     */
    public const int WIDGET_ROWS = 5;

    /**
     * @return array{rows: list<array<string, mixed>>, action: array{label: string, url: string}|null, limited: bool}
     */
    public function page(TenantUsageView $view): array
    {
        return [
            'rows'    => array_map($this->row(...), $view->rows),
            'action'  => $view->action,
            'limited' => $view->limited,
        ];
    }

    /**
     * A short list for the dashboard: limits that are almost used up or reached first, then the fullest of the rest.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, action: array{label: string, url: string}|null, limited: bool}
     */
    public function widget(TenantUsageView $view): array
    {
        $limited = array_values(array_filter(
            $view->rows,
            static fn (UsageRow $row): bool => null !== $row->limit && UsageState::Unavailable !== $row->state,
        ));

        usort($limited, static fn (UsageRow $a, UsageRow $b): int => [$b->state->needsAttention(), $b->percent ?? -1] <=> [$a->state->needsAttention(), $a->percent ?? -1]);

        return [
            'rows'        => array_map($this->row(...), array_slice($limited, 0, self::WIDGET_ROWS)),
            'total'       => count($limited),
            'unavailable' => count(array_filter($view->rows, static fn (UsageRow $row): bool => UsageState::Unavailable === $row->state)),
            'action'      => $view->action,
            'limited'     => $view->limited,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(UsageRow $row): array
    {
        $periodEnds = null === $row->periodEndsAt ? null : CarbonImmutable::instance($row->periodEndsAt)->utc();

        return [
            'key'             => $row->key,
            'label'           => LimitNoticeFormat::label($row->key, $row->label),
            'kind'            => $row->kind->value,
            'state'           => $row->state->value,
            'used'            => $row->used,
            'limit'           => $row->limit,
            'usedText'        => null === $row->used ? null : LimitNoticeFormat::amount($row->used, $row->kind),
            'limitText'       => null === $row->limit ? null : LimitNoticeFormat::amount($row->limit, $row->kind),
            'percent'         => $row->percent,
            'periodEndsAt'    => $periodEnds?->toIso8601String(),
            'periodEndsLabel' => $periodEnds?->locale(app()->getLocale())->isoFormat('LL'),
        ];
    }
}
