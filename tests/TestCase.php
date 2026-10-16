<?php

declare(strict_types=1);

namespace Tests;

use App\Domains\Flow\Call\Egress\HostResolverInterface;
use Fapost\Testing\Concerns\IsolatesLandlordPerParallelProcess;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeHostResolver;

abstract class TestCase extends BaseTestCase
{
    use IsolatesLandlordPerParallelProcess;

    /**
     * Stands in for DNS in every test; see {@see FakeHostResolver}.
     */
    protected FakeHostResolver $hostResolver;

    protected function setUp(): void
    {
        parent::setUp();

        // Pages are rendered without a Vite manifest: tests assert on
        // responses, not on compiled assets, and CI has no public/build.
        $this->withoutVite();

        $this->hostResolver = new FakeHostResolver();
        $this->app->instance(HostResolverInterface::class, $this->hostResolver);
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
}
