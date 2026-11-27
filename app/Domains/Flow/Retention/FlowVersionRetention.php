<?php

declare(strict_types=1);

namespace App\Domains\Flow\Retention;

/**
 * How many published versions of each flow are kept: one platform setting for the whole installation.
 *
 * {@see self::SETTING} is the number of highest versions every flow keeps; 0, the default, keeps every version and
 * turns the daily `flow:prune-versions` off until an operator sets a number (50 is the recommended one). A version is
 * deleted only when {@see FlowVersionPruner} finds nothing that still needs it.
 */
final class FlowVersionRetention
{
    /** The platform setting with the number of versions each flow keeps; 0 keeps them all. */
    public const string SETTING = 'flow.versions.keep_last';

    public const int DEFAULT_KEEP = 0;

    public const int RECOMMENDED_KEEP = 50;

    public const int MAX_KEEP = 10000;

    /**
     * Parses an environment value, the install default of the setting: a whole number from 0 (keep every version) to
     * {@see self::MAX_KEEP}; anything else, unset included, is `$default`.
     */
    public static function parseKeep(mixed $raw, int $default): int
    {
        if (! is_int($raw) && ! is_string($raw)) {
            return $default;
        }

        $keep = filter_var(is_string($raw) ? mb_trim($raw) : $raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => self::MAX_KEEP],
        ]);

        return false === $keep ? $default : $keep;
    }
}
