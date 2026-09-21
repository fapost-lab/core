<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;

abstract class TestCase extends BaseTestCase
{
    /**
     * The application is rebuilt for every test, and with it the config, so the
     * landlord connection is pointed at this process's database each time.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $this->isolateLandlordPerParallelProcess();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Pages are rendered without a Vite manifest: tests assert on
        // responses, not on compiled assets, and CI has no public/build.
        $this->withoutVite();
    }

    /**
     * Landlord database this test uses under `--parallel`.
     *
     * A plain database test (`DatabaseTransactions`) gets the landlord tables from
     * the `migrate` Laravel runs when it creates the process's default database,
     * which records them in that database. Pointing landlord at the same database
     * keeps tables and record together, and Laravel recreates both at once.
     * {@see Feature\FeatureTestCase} wipes and migrates the landlord itself and
     * uses a database of its own.
     */
    protected function parallelLandlordDatabase(string $landlordDatabase, string $defaultDatabase, string $token): string
    {
        return "{$defaultDatabase}_test_{$token}";
    }

    /**
     * Under `php artisan test --parallel` Laravel gives every process its own copy
     * of the default database only; the landlord connection would stay shared, and
     * processes would migrate and wipe the same landlord tables. Only PostgreSQL
     * needs this: SQLite's `:memory:` is already private to the process.
     */
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
