<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Console\Platform\UnservedChannelsFinderInterface;
use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionManifestValidator;
use App\Domains\Solution\Services\SolutionSchemas;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Fapost\Foundation\Solution\Manifest\ManifestParser;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\FeatureTestCase;
use Tests\Fixtures\Solution\Actions\FeedbackSubmitAction;
use Tests\Support\InstallsMigratingSolutions;
use Tests\Support\NoUnservedChannels;

/**
 * `platform:update` with the real migrator, against the seeded tenant `main`: a Solution whose new migration fails is
 * rolled back alone in that schema, while the platform and another Solution's new migration stay applied and the
 * activate phase still caches the catalog.
 */
final class PlatformUpdateSolutionRollbackTest extends FeatureTestCase
{
    use InstallsMigratingSolutions;

    private const string CREATE_ITEMS = '2026_11_16_000001_create_items_table';

    private const string SEED_ITEMS = '2026_11_16_000002_seed_items';

    private SolutionCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installMigratingSolutions([
            'alpha' => [self::CREATE_ITEMS => self::migrationCreating('alpha_items')],
            'beta'  => [self::CREATE_ITEMS => self::migrationCreating('beta_items')],
        ]);

        $vendor      = $this->migratingVendor ?? throw new LogicException('No migrating Solutions are installed.');
        $this->cache = new SolutionCache(dirname($vendor->path) . '/fapost-solutions.php');
        $this->app->instance(SolutionCache::class, $this->cache);
        $this->app->instance(SolutionDiscoverer::class, new SolutionDiscoverer(
            new InstalledPackagesReader($vendor->path),
            new SolutionManifestValidator(new ManifestParser()),
        ));
        $this->app->instance(UnservedChannelsFinderInterface::class, new NoUnservedChannels());

        $registry = $this->app->make(ActionHandlerRegistry::class);
        $registry->register(FeedbackSubmitAction::class, 'alpha', 'alpha.run');
        $registry->register(FeedbackSubmitAction::class, 'beta', 'beta.run');
    }

    protected function tearDown(): void
    {
        $this->removeMigratingSolutions();

        parent::tearDown();
    }

    public function test_a_failing_solution_migration_is_rolled_back_alone_and_the_update_still_activates(): void
    {
        $this->inMainTenant(fn () => $this->app->make(SolutionSchemas::class)->bringCurrent('alpha'));
        $this->inMainTenant(fn () => $this->app->make(SolutionSchemas::class)->bringCurrent('beta'));
        $this->upgradeMigratingSolution('alpha', self::SEED_ITEMS, self::migrationRunning(
            "DB::table('alpha_items')->insert(['id' => (string) Str::uuid(), 'title' => 'half']);\n"
            . "throw new RuntimeException('alpha is broken');",
        ));
        $this->upgradeMigratingSolution('beta', self::SEED_ITEMS, self::migrationInserting('beta_items'));
        $this->app->forgetScopedInstances();

        $this->artisan('platform:update')
            ->expectsOutputToContain('✘ main alpha: Solution "alpha": migration "' . self::SEED_ITEMS . '" failed: alpha is broken')
            ->expectsOutputToContain('✔ main beta: ' . self::SEED_ITEMS)
            ->expectsOutputToContain('Phase 4 (migrate Solutions): Solution "alpha" failed to migrate')
            ->expectsOutputToContain('Solution catalog cached')
            ->expectsOutputToContain('1 Solution(s) failed to migrate: alpha.')
            ->assertExitCode(1);

        $this->inMainTenant(function (): void {
            $this->assertSame(['alpha' => self::CREATE_ITEMS, 'beta' => self::SEED_ITEMS], $this->footprints());
            $this->assertSame([self::CREATE_ITEMS], $this->journal('alpha'));
            $this->assertSame([self::CREATE_ITEMS, self::SEED_ITEMS], $this->journal('beta'));
            $this->assertSame(0, DB::table('alpha_items')->count(), 'The failed run kept nothing it wrote.');
            $this->assertSame(1, DB::table('beta_items')->count());
        });

        $this->assertSame(['alpha', 'beta'], array_map(static fn (InstalledSolution $solution): string => $solution->id, $this->cache->read() ?? []));
    }

    private function inMainTenant(callable $callback): mixed
    {
        $tenant = $this->app->make(TenantRepositoryInterface::class)->findBySlug('main');
        $this->assertNotNull($tenant);

        $result = $this->app->make(TenantSwitcher::class)->runForTenant($tenant, $callback(...));
        $this->app->forgetScopedInstances();

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function footprints(): array
    {
        /** @var array<string, string> $latest */
        $latest = DB::table('solution_schemas')->orderBy('solution_id')->pluck('latest_migration', 'solution_id')->all();

        return $latest;
    }

    /**
     * @return list<string>
     */
    private function journal(string $solutionId): array
    {
        /** @var list<string> $names */
        $names = DB::table('solution_migrations')->where('solution_id', $solutionId)->orderBy('migration')->pluck('migration')->all();

        return $names;
    }
}
