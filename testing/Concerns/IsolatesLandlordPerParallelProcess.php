<?php

declare(strict_types=1);

namespace Fapost\Testing\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;

/**
 * Gives each `--parallel` process a landlord database of its own on PostgreSQL.
 *
 * Under `php artisan test --parallel` Laravel gives every process its own copy of the default database only; the
 * landlord connection would stay shared, and processes would migrate and wipe the same landlord tables. Only PostgreSQL
 * needs this: SQLite's `:memory:` is already private to the process. Shared by Core's base test case and the Solution
 * testing kit, which do not extend each other.
 *
 * @internal part of the kit's database set-up, not its API
 */
trait IsolatesLandlordPerParallelProcess
{
    /**
     * Landlord database this test uses under `--parallel`.
     */
    abstract protected function parallelLandlordDatabase(string $landlordDatabase, string $defaultDatabase, string $token): string;

    /**
     * The application, and with it the config, is rebuilt for every test, so the landlord connection is pointed at
     * this process's database each time.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $this->isolateLandlordPerParallelProcess();
    }

    private function isolateLandlordPerParallelProcess(): void
    {
        $token = ParallelTesting::token();

        if (false === $token || null === $token || 'pgsql' !== config('database.connections.landlord.driver')) {
            return;
        }

        $database = $this->parallelLandlordDatabase(
            (string) config('database.connections.landlord.database'),
            (string) config('database.connections.' . config('database.default') . '.database'),
            (string) $token,
        );

        config(['database.connections.landlord.database' => $database]);
        DB::purge('landlord');
    }
}
