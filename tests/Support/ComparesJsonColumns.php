<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Compares a value read back from a JSON column the way JSON compares it: the keys of an object in any order, the
 * items of a list in theirs. A `jsonb` column (Postgres) re-orders the keys of an object it stores, SQLite keeps them,
 * so `assertSame` on an object read back passes on one database and fails on the other.
 */
trait ComparesJsonColumns
{
    protected function assertSameJson(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertSame(self::sortedJsonKeys($expected), self::sortedJsonKeys($actual), $message);
    }

    private static function sortedJsonKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sortedJsonKeys(...), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
