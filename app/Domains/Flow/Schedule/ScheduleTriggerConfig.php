<?php

declare(strict_types=1);

namespace App\Domains\Flow\Schedule;

use Cron\CronExpression;
use DateTimeZone;
use Throwable;

/**
 * A schedule trigger's config: a cron expression of five fields (or one of the macros `@hourly`, `@daily`,
 * `@weekly`, `@monthly`, `@yearly`, `@annually`, `@midnight`, shorthands for five fields) read in an IANA time zone.
 * A six-field expression (with seconds) is refused, so a schedule fires at most once a minute. The historical key
 * `target` is read as the time zone when `timezone` is absent.
 */
final readonly class ScheduleTriggerConfig
{
    private function __construct(
        public string $cron,
        public string $timezone,
    ) {
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidScheduleConfigException
     */
    public static function fromConfig(array $config): self
    {
        $cron     = $config['cron'] ?? null;
        $timezone = $config['timezone'] ?? $config['target'] ?? null;

        if (! is_string($cron) || ! self::isFiveFieldCron(mb_trim($cron))) {
            throw InvalidScheduleConfigException::cron();
        }

        if (! is_string($timezone) || ! in_array(mb_trim($timezone), DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw InvalidScheduleConfigException::timezone();
        }

        return new self(mb_trim($cron), mb_trim($timezone));
    }

    private static function isFiveFieldCron(string $cron): bool
    {
        if ('' === $cron) {
            return false;
        }

        $shortcut = mb_strtolower($cron);

        if (str_starts_with($shortcut, '@')) {
            return isset(CronExpression::MAPPINGS[$shortcut]);
        }

        if (5 !== count(preg_split('/\s+/', $cron, -1, PREG_SPLIT_NO_EMPTY) ?: [])) {
            return false;
        }

        try {
            new CronExpression($cron);
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
