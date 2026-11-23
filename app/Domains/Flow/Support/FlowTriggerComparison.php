<?php

declare(strict_types=1);

namespace App\Domains\Flow\Support;

/**
 * How two lists of a flow's triggers compare: the rule a publish, its audit trail, the rights check on it and the sync
 * of the live rows all share. Triggers are matched by lowercased id; one is changed when its type, active flag or
 * priority differ, or its config says something else.
 *
 * @phpstan-type Trigger array{id: string, type: string, is_active: bool, priority: int, config: array<string, mixed>}
 */
final class FlowTriggerComparison
{
    /**
     * What going from `$before` to `$after` adds, changes and removes. Added and changed follow the order of `$after`,
     * removed the order of `$before`.
     *
     * @param  list<Trigger>  $before
     * @param  list<Trigger>  $after
     *
     * @return array{added: list<Trigger>, changed: list<array{before: Trigger, after: Trigger}>, removed: list<Trigger>}
     */
    public static function diff(array $before, array $after): array
    {
        $remaining = [];

        foreach ($before as $trigger) {
            $remaining[mb_strtolower($trigger['id'])] = $trigger;
        }

        $added   = [];
        $changed = [];

        foreach ($after as $trigger) {
            $id      = mb_strtolower($trigger['id']);
            $current = $remaining[$id] ?? null;
            unset($remaining[$id]);

            if (null === $current) {
                $added[] = $trigger;

                continue;
            }

            if ($current['type'] !== $trigger['type']
                || $current['is_active'] !== $trigger['is_active']
                || $current['priority'] !== $trigger['priority']
                || ! self::sameConfig($current['config'], $trigger['config'])) {
                $changed[] = ['before' => $current, 'after' => $trigger];
            }
        }

        return ['added' => $added, 'changed' => $changed, 'removed' => array_values($remaining)];
    }

    /**
     * Whether two trigger configs say the same, whatever the order of their keys: Postgres `jsonb` returns object
     * keys in its own order, so a config read back from a row never compares equal to the one written as is. The
     * order of a list is kept, it is part of the value.
     *
     * @param  array<array-key, mixed>  $left
     * @param  array<array-key, mixed>  $right
     */
    public static function sameConfig(array $left, array $right): bool
    {
        return self::canonical($left) === self::canonical($right);
    }

    /**
     * @param  array<array-key, mixed>  $value
     *
     * @return array<array-key, mixed>
     */
    private static function canonical(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
