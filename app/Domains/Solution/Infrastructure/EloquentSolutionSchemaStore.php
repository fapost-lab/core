<?php

declare(strict_types=1);

namespace App\Domains\Solution\Infrastructure;

use App\Domains\Solution\Contracts\SolutionSchemaStoreInterface;
use Illuminate\Support\Facades\DB;

/**
 * Reads `solution_schemas` of the tenant schema the caller is switched into (the default connection).
 */
final class EloquentSolutionSchemaStore implements SolutionSchemaStoreInterface
{
    public const string TABLE = 'solution_schemas';

    public function appliedDigests(): array
    {
        /** @var array<string, string> $digests */
        $digests = DB::table(self::TABLE)->pluck('migrations_digest', 'solution_id')->all();

        return $digests;
    }
}
