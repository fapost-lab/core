<?php

declare(strict_types=1);

namespace App\Domains\Flow\Schedule;

use InvalidArgumentException;

/**
 * A schedule trigger's config that cannot run: {@see self::$errorCode} is the publish error the builder shows
 * (`invalid_schedule_cron`, `invalid_schedule_timezone`).
 */
final class InvalidScheduleConfigException extends InvalidArgumentException
{
    public const string INVALID_CRON = 'invalid_schedule_cron';

    public const string INVALID_TIMEZONE = 'invalid_schedule_timezone';

    private function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function cron(): self
    {
        return new self(self::INVALID_CRON, 'The schedule needs a cron expression of five fields (minute, hour, day of month, month, day of week).');
    }

    public static function timezone(): self
    {
        return new self(self::INVALID_TIMEZONE, 'The schedule needs a time zone from the IANA list, such as Europe/Berlin.');
    }
}
