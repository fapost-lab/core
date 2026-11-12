<?php

declare(strict_types=1);

namespace Tests\Unit\Tooling;

use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\SolutionCache;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Tests\Support\CoreOnlyApplication;
use Tests\Support\CorePackageManifest;
use Tests\Support\OverlayPackages;
use Tests\TestCase;

/**
 * Core's tests run against Core alone, even where `composer overlay` installed packages into vendor/.
 */
final class CoreTestsWithoutOverlayTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir() . '/fapost-core-overlay-' . bin2hex(random_bytes(6));
        mkdir($this->base . '/vendor/composer', 0o777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->base);

        parent::tearDown();
    }

    public function test_an_overlay_package_is_one_installed_but_not_locked_by_core(): void
    {
        $this->install(
            installed: ['acme/core-lib', 'acme/core-dev-tool', 'acme/operator', 'acme/operator-dependency', 'acme/solution'],
            locked: ['acme/core-lib'],
            lockedDev: ['acme/core-dev-tool'],
        );

        $this->assertSame(
            ['acme/operator', 'acme/operator-dependency', 'acme/solution'],
            OverlayPackages::in($this->base, $this->base . '/vendor'),
        );
    }

    public function test_nothing_is_an_overlay_package_without_a_lock_to_compare_with(): void
    {
        $this->install(installed: ['acme/operator'], locked: null);

        $this->assertSame([], OverlayPackages::in($this->base, $this->base . '/vendor'));
    }

    public function test_package_discovery_leaves_out_the_overlay_and_keeps_what_core_ignores(): void
    {
        $this->install(
            installed: ['acme/core-lib', 'acme/core-hidden', 'acme/operator'],
            locked: ['acme/core-lib', 'acme/core-hidden'],
            providers: [
                'acme/core-lib'    => 'Acme\CoreLib\Provider',
                'acme/core-hidden' => 'Acme\CoreHidden\Provider',
                'acme/operator'    => 'Acme\Operator\Provider',
            ],
        );
        file_put_contents($this->base . '/composer.json', json_encode(['extra' => ['laravel' => ['dont-discover' => ['acme/core-hidden']]]]));
        mkdir($this->base . '/bootstrap/cache', 0o777, true);

        $manifestPath = $this->base . '/bootstrap/cache/packages.php';
        $manifest     = new CorePackageManifest(new Filesystem(), $this->base, $manifestPath, OverlayPackages::in($this->base, $this->base . '/vendor'));

        $this->assertSame(['Acme\CoreLib\Provider'], $manifest->providers());
        $this->assertFileDoesNotExist($manifestPath);
    }

    public function test_the_application_of_a_test_discovers_packages_and_caches_solutions_apart_from_the_developers_application(): void
    {
        $this->assertInstanceOf(CorePackageManifest::class, $this->app->make(PackageManifest::class));
        $this->assertNotSame($this->app->bootstrapPath('cache/fapost-solutions.php'), $this->app->make(SolutionCache::class)->path());
    }

    public function test_the_installed_packages_reader_of_the_container_leaves_out_the_overlay_solutions(): void
    {
        file_put_contents($this->base . '/vendor/composer/installed.json', json_encode(['packages' => [
            ['name' => 'acme/core-solution', 'type' => 'fapost-solution'],
            ['name' => 'acme/overlay-solution', 'type' => 'fapost-solution'],
        ]]));
        file_put_contents($this->base . '/composer.lock', json_encode(['packages' => [['name' => 'acme/core-solution']], 'packages-dev' => []]));

        $app     = new Application($this->base);
        $overlay = CoreOnlyApplication::apply($app);

        $this->assertSame(['acme/overlay-solution'], $overlay);
        $this->assertSame(
            ['acme/core-solution'],
            array_map(static fn ($package): string => $package->name, $app->make(InstalledPackagesReader::class)->candidates()),
        );
        $this->assertSame($this->base . '/bootstrap/cache/fapost-solutions.testing.php', $app->make(SolutionCache::class)->path());
    }

    public function test_the_tests_keep_their_own_services_cache(): void
    {
        $this->assertSame($this->app->bootstrapPath('cache/services.testing.php'), $this->app->getCachedServicesPath());
    }

    /**
     * @param  list<string>  $installed
     * @param  list<string>|null  $locked  null writes no composer.lock
     * @param  list<string>  $lockedDev
     * @param  array<string, string>  $providers  a package's Laravel service provider, by package name
     */
    private function install(array $installed, ?array $locked, array $lockedDev = [], array $providers = []): void
    {
        $packages = array_map(
            static fn (string $name): array => isset($providers[$name])
                ? ['name' => $name, 'extra' => ['laravel' => ['providers' => [$providers[$name]]]]]
                : ['name' => $name],
            $installed,
        );

        file_put_contents($this->base . '/vendor/composer/installed.json', json_encode(['packages' => $packages]));

        if (null !== $locked) {
            file_put_contents($this->base . '/composer.lock', json_encode([
                'packages'     => array_map(static fn (string $name): array => ['name' => $name], $locked),
                'packages-dev' => array_map(static fn (string $name): array => ['name' => $name], $lockedDev),
            ]));
        }
    }
}
