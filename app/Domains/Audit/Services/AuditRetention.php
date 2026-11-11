<?php

declare(strict_types=1);

namespace App\Domains\Audit\Services;

/**
 * How long audit entries are kept: the platform setting `audit.retention_days`, one value for every tenant.
 * 0 keeps them forever.
 */
final class AuditRetention
{
    /** The platform setting that holds the retention in days. */
    public const string SETTING = 'audit.retention_days';

    /** The retention when nothing else says otherwise: a year. */
    public const int DEFAULT_DAYS = 365;

    public const int MAX_DAYS = 3650;

    /**
     * Parses `AUDIT_RETENTION_DAYS`, the install default of the setting: a whole number from 0 (keep forever) to
     * {@see self::MAX_DAYS}; anything else, unset included, is {@see self::DEFAULT_DAYS}.
     */
    public static function parseDays(mixed $raw): int
    {
        if (! is_int($raw) && ! is_string($raw)) {
            return self::DEFAULT_DAYS;
        }

        $days = filter_var(is_string($raw) ? mb_trim($raw) : $raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => self::MAX_DAYS],
        ]);

        return false === $days ? self::DEFAULT_DAYS : $days;
    }
}
