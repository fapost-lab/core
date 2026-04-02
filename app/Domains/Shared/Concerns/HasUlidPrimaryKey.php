<?php

declare(strict_types=1);

namespace App\Domains\Shared\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Str;

trait HasUlidPrimaryKey
{
    use HasUlids;

    public function newUniqueId(): string
    {
        return mb_strtolower((string) Str::ulid()->toRfc4122());
    }

    /**
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
