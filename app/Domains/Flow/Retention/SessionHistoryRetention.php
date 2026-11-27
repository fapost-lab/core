<?php

declare(strict_types=1);

namespace App\Domains\Flow\Retention;

/**
 * How long the history of a finished conversation session is kept: one platform setting for the whole installation.
 *
 * {@see self::SETTING} days after a conversation session finished, the daily `flow:prune-session-history` deletes its
 * whole `flow_session_history` ({@see SessionHistoryPruner}). 0, the default, keeps it forever: history that already
 * exists is deleted only once an operator sets a period. An automation run's history is not governed by it — it goes
 * with its run ({@see AutomationRunRetention}).
 */
final class SessionHistoryRetention
{
    /** The platform setting with the days a finished conversation session's history is kept. */
    public const string SETTING = 'flow.session_history.retention_days';

    public const int DEFAULT_DAYS = 0;

    public const int MAX_DAYS = AutomationRunRetention::MAX_DAYS;

    /**
     * Parses an environment value, the install default of the setting: a whole number from 0 (keep forever) to
     * {@see self::MAX_DAYS}; anything else, unset included, is {@see self::DEFAULT_DAYS}.
     */
    public static function parseDays(mixed $raw): int
    {
        return AutomationRunRetention::parseDays($raw, self::DEFAULT_DAYS);
    }
}
