<?php

declare(strict_types=1);

namespace App\Domains\Flow\Orchestration;

use App\Domains\Flow\DTOs\FlowRunOrigin;

/**
 * The fire key of a re-run from the start: `rerun:<source session id>:<attempt>`, the attempt numbering the re-runs of
 * that source from 1, one above the highest that has a run.
 *
 * It is deterministic, so two requests to re-run the same run before the first run exists make the same key and the
 * trigger's unique `(trigger_id, fire_key)` turns them into one run. The prefix meets no other trigger's key: a webhook
 * delivery's starts with `wh:`, an event's is a bare ULID. A re-run keeps its source's trigger, so its key lives beside
 * the source's own within that trigger; at most `6 + 36 + 1 + 10` characters, inside
 * {@see FlowRunOrigin::MAX_FIRE_KEY_LENGTH}.
 */
final class RerunFireKey
{
    private const string PREFIX = 'rerun:';

    public static function for(string $sourceSessionId, int $attempt): string
    {
        return self::prefixOf($sourceSessionId) . $attempt;
    }

    /**
     * What every re-run of the source's fire key starts with; a `LIKE` pattern as it is, since a session id holds only
     * hex digits and dashes.
     */
    public static function prefixOf(string $sourceSessionId): string
    {
        return self::PREFIX . $sourceSessionId . ':';
    }

    public static function isRerun(string $fireKey): bool
    {
        return str_starts_with($fireKey, self::PREFIX);
    }

    /**
     * The attempt a re-run's fire key names, or null when the key is not a re-run's.
     */
    public static function attemptOf(string $fireKey): ?int
    {
        if (null === self::sourceId($fireKey)) {
            return null;
        }

        $attempt = mb_substr($fireKey, (int) mb_strrpos($fireKey, ':') + 1);

        return 1 === preg_match('/^[1-9]\d{0,9}$/', $attempt) ? (int) $attempt : null;
    }

    /**
     * The session a re-run's fire key was made from, or null when the key is not a re-run's.
     */
    public static function sourceId(string $fireKey): ?string
    {
        if (! self::isRerun($fireKey)) {
            return null;
        }

        $rest  = mb_substr($fireKey, mb_strlen(self::PREFIX));
        $colon = mb_strrpos($rest, ':');

        if (false === $colon || 0 === $colon) {
            return null;
        }

        return mb_substr($rest, 0, $colon);
    }
}
