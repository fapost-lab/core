<?php

declare(strict_types=1);

namespace App\Domains\Flow\Orchestration;

use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * How long an automation run may live (adr-20261119-automation-run-deadline).
 *
 * A run's deadline is fixed when it starts — its start plus the platform setting {@see self::SETTING} — and written on
 * its root session; subflow children copy it. Changing the setting never moves the deadline of a run already going.
 * A row written before the deadline existed is measured from its `created_at` with today's setting
 * ({@see self::fallbackCutoff()}), so no automation run is exempt.
 *
 * What makes a live session overdue is written here once, in two forms that must agree: {@see self::whereOverdue()}
 * for the query that finds candidates without a lock, and {@see self::isOverdue()} for the re-check under the lock.
 *
 * Bound `scoped`: the setting is read through the scoped settings reader.
 */
final readonly class AutomationRunDeadline
{
    /** The platform setting with the hours an automation run may live. */
    public const string SETTING = 'flow.automation.max_run_hours';

    public const int DEFAULT_HOURS = 24;

    public const int MIN_HOURS = 1;

    public const int MAX_HOURS = 720;

    /**
     * How long a `pending` run may wait for the job that starts it before it counts as lost: the whole retry window of
     * that job (one postponement while its tenant is stopped, then its busy-lock retries), plus an hour to spare.
     */
    public const int PENDING_LOST_AFTER_SECONDS = AutomationRunner::START_RETRY_SECONDS
        + RespectsTenantAccessMode::POSTPONE_SECONDS
        + 3600;

    public function __construct(
        private PlatformSettingsReaderInterface $platformSettings,
    ) {
    }

    /**
     * The deadline of a run starting at {@code $start}.
     */
    public function for(DateTimeInterface $start): CarbonImmutable
    {
        return CarbonImmutable::instance($start)->addHours($this->hours());
    }

    /**
     * A run without a written deadline is overdue once it was created before this moment.
     */
    public function fallbackCutoff(DateTimeInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->subHours($this->hours());
    }

    /**
     * A `pending` run created before this moment has lost the job that was to start it.
     */
    public function pendingLostCutoff(DateTimeInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->subSeconds(self::PENDING_LOST_AFTER_SECONDS);
    }

    /**
     * When {@code $session}'s run is due: its written deadline, or — for a row written before the deadline existed —
     * its `created_at` plus today's setting. Null only for a row with neither.
     */
    public function effectiveFor(FlowSession $session): ?CarbonImmutable
    {
        if (null !== $session->deadline_at) {
            return CarbonImmutable::instance($session->deadline_at);
        }

        return null !== $session->created_at ? $this->for($session->created_at) : null;
    }

    /**
     * Whether a live session is overdue at {@code $now}: past its effective deadline ({@see self::effectiveFor()}),
     * or still `pending` past {@see self::PENDING_LOST_AFTER_SECONDS}. The PHP form of {@see self::whereOverdue()}.
     */
    public function isOverdue(FlowSession $session, DateTimeInterface $now): bool
    {
        $deadline = $this->effectiveFor($session);

        if (null !== $deadline && $deadline->lt($now)) {
            return true;
        }

        return FlowSessionStatus::Pending === $session->status
            && null !== $session->created_at
            && $session->created_at->lt($this->pendingLostCutoff($now));
    }

    /**
     * Narrow {@code $query} to the sessions overdue at {@code $now}. The SQL form of {@see self::isOverdue()}: a row
     * without a deadline is compared by its `created_at` against {@see self::fallbackCutoff()}, which is the same test.
     *
     * @param  Builder<FlowSession>  $query
     * @return Builder<FlowSession>
     */
    public function whereOverdue(Builder $query, DateTimeInterface $now): Builder
    {
        return $query->where(function (Builder $query) use ($now): void {
            $query->where('deadline_at', '<', $now)
                ->orWhere(function (Builder $query) use ($now): void {
                    $query->whereNull('deadline_at')
                        ->where('created_at', '<', $this->fallbackCutoff($now));
                })
                ->orWhere(function (Builder $query) use ($now): void {
                    $query->where('status', FlowSessionStatus::Pending->value)
                        ->where('created_at', '<', $this->pendingLostCutoff($now));
                });
        });
    }

    /**
     * The setting, held within its bounds: the settings store checks only a stored value, so an installation default
     * outside them is clamped here, as the segment budget is.
     */
    private function hours(): int
    {
        return min(self::MAX_HOURS, max(self::MIN_HOURS, $this->platformSettings->integer(self::SETTING)));
    }
}
