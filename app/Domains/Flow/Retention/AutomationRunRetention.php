<?php

declare(strict_types=1);

namespace App\Domains\Flow\Retention;

use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;

/**
 * How long finished automation runs are kept: two platform settings, one value each for the whole installation.
 *
 * A succeeded run — `completed`, or `ended` with `end_status = success` — is kept
 * {@see self::SUCCEEDED_SETTING} days (7 by default); every other finished run is kept {@see self::FAILED_SETTING}
 * days (30). 0 keeps that class forever. Only the statuses in {@see self::TERMINAL} count as finished: a status
 * outside that list, including one added later, is never pruned.
 */
final class AutomationRunRetention
{
    /** The platform setting with the days a succeeded automation run is kept. */
    public const string SUCCEEDED_SETTING = 'flow.automation_runs.succeeded_retention_days';

    /** The platform setting with the days any other finished automation run is kept. */
    public const string FAILED_SETTING = 'flow.automation_runs.failed_retention_days';

    public const int DEFAULT_SUCCEEDED_DAYS = 7;

    public const int DEFAULT_FAILED_DAYS = 30;

    public const int MAX_DAYS = 3650;

    /**
     * The statuses a session never leaves: the only ones retention may prune. The same list as
     * {@see FlowSessionStatus::terminal()}, restated because a constant cannot call it; a test holds the two equal, so
     * a terminal status added to the enum must be added here too.
     *
     * @var list<FlowSessionStatus>
     */
    public const array TERMINAL = [
        FlowSessionStatus::Completed,
        FlowSessionStatus::Ended,
        FlowSessionStatus::Failed,
        FlowSessionStatus::Cancelled,
        FlowSessionStatus::Expired,
        FlowSessionStatus::TerminatedByUser,
    ];

    /**
     * The statuses of a session that is still running or waiting. Never pruned; listed so a test can require every
     * status to be classified one way or the other.
     *
     * @var list<FlowSessionStatus>
     */
    public const array LIVE = [
        FlowSessionStatus::Pending,
        FlowSessionStatus::Active,
        FlowSessionStatus::WaitingInput,
        FlowSessionStatus::Paused,
        FlowSessionStatus::PausedSubflow,
    ];

    /**
     * The values of {@see self::TERMINAL}, for a query.
     *
     * @return list<string>
     */
    public static function terminalValues(): array
    {
        return array_map(static fn (FlowSessionStatus $status): string => $status->value, self::TERMINAL);
    }

    public static function isTerminal(FlowSessionStatus $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    /**
     * Whether a finished run succeeded. Anything else that is terminal — failed, expired, cancelled, terminated, or
     * ended with another or no end status — is kept for the longer failed retention.
     */
    public static function isSucceeded(FlowSessionStatus $status, ?string $endStatus): bool
    {
        return FlowSessionStatus::Completed === $status
            || (FlowSessionStatus::Ended === $status && EndStatus::Success->value === $endStatus);
    }

    /**
     * {@see self::isSucceeded()} as a SQL condition over `status` and `end_status`, built from the same enum values so
     * the two cannot drift.
     */
    public static function succeededSql(): string
    {
        return sprintf(
            "(status = '%s' OR (status = '%s' AND COALESCE(end_status, '') = '%s'))",
            FlowSessionStatus::Completed->value,
            FlowSessionStatus::Ended->value,
            EndStatus::Success->value,
        );
    }

    /**
     * Parses an environment value, the install default of a setting: a whole number from 0 (keep forever) to
     * {@see self::MAX_DAYS}; anything else, unset included, is `$default`.
     */
    public static function parseDays(mixed $raw, int $default): int
    {
        if (! is_int($raw) && ! is_string($raw)) {
            return $default;
        }

        $days = filter_var(is_string($raw) ? mb_trim($raw) : $raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => self::MAX_DAYS],
        ]);

        return false === $days ? $default : $days;
    }
}
