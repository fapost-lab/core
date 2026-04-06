<?php

declare(strict_types=1);

namespace App\Domains\Shared\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Str;

/**
 * ULID primary key helper.
 *
 * Uses Laravel's {@see HasUlids} but enforces UUID output (RFC-4122) lowercased.
 */
trait HasUlidPrimaryKey
{
    use HasUlids;

    /**
     * Generate a new ULID-based UUID string for the primary key.
     */
    public function newUniqueId(): string
    {
        return mb_strtolower((string) Str::ulid()->toRfc4122());
    }

    /**
     * List of model attributes considered primary identifiers for ULID generation.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['id'];
    }

    protected function isValidUniqueId($value): bool
    {
        return is_string($value) && Str::isUuid($value);
    }
}
