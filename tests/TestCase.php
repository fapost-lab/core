<?php

declare(strict_types=1);

namespace Tests;

use App\Domains\Flow\Call\Egress\HostResolverInterface;
use Fapost\Testing\Concerns\IsolatesLandlordPerParallelProcess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Tests\Support\CoreOnlyApplication;
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
     * The application as Core alone has it, whatever a local `composer overlay` installed into vendor/.
     *
     * The wiring is {@see CoreOnlyApplication}. The cached-config and cached-routes handling is the parent's, repeated
     * because this override replaces it and both must run before the kernel bootstraps.
     */
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require Application::inferBasePath() . '/bootstrap/app.php';

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        if (isset(CachedState::$cachedConfig, $this->traitsUsedByTest[WithCachedConfig::class])) {
            $this->markConfigCached($app);
        }

        if (isset(CachedState::$cachedRoutes, $this->traitsUsedByTest[WithCachedRoutes::class])) {
            $app->booting(fn () => $this->markRoutesCached($app));
        }

        CoreOnlyApplication::apply($app);

        $app->make(Kernel::class)->bootstrap();

        return $app;
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
