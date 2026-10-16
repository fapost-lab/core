<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Console\Platform\PlatformMigratorInterface;
use App\Console\Platform\UnservedChannelsFinderInterface;
use App\Domains\Solution\Services\PublishedSolutionUi;
use App\Domains\Solution\Services\SolutionCache;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\Support\InstallsShowcaseSolution;
use Tests\Support\NoUnservedChannels;
use Tests\TestCase;

/**
 * `platform:update` checks a Solution's console UI with its manifest, before migrations, and after them publishes its
 * `dist/` to `public/solutions/<id>/<hash>/` and records it in the cache. Not a database test: the migrator is faked.
 */
final class SolutionUiPublicationTest extends TestCase
{
    use InstallsShowcaseSolution;

    private int $migrations = 0;

    /** @var list<string> */
    private array $copies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(UnservedChannelsFinderInterface::class, new NoUnservedChannels());
        $this->app->instance(PlatformMigratorInterface::class, new class ($this) implements PlatformMigratorInterface {
            public function __construct(private readonly SolutionUiPublicationTest $test)
            {
            }

            public function migrate(OutputInterface $output): bool
            {
                $this->test->recordMigration();

                return true;
            }
        });
    }

    protected function tearDown(): void
    {
        $this->removeShowcase();

        foreach ($this->copies as $copy) {
            (new Filesystem())->deleteDirectory($copy);
        }

        parent::tearDown();
    }

    public function recordMigration(): void
    {
        $this->migrations++;
    }

    public function test_the_update_publishes_the_bundle_under_its_hash_and_caches_it(): void
    {
        $this->installShowcase(publish: false);

        $this->artisan('platform:update')
            ->expectsOutputToContain('Console UI published (1 Solution(s))')
            ->assertExitCode(0);

        $hash      = mb_substr(hash_file('sha256', self::showcasePackage() . '/dist/fapost-ui.json'), 0, 12);
        $published = public_path("solutions/showcase/{$hash}");

        $this->assertSame(1, $this->migrations);
        $this->assertFileExists($published . '/fapost-ui.json');
        $this->assertFileEquals(self::showcasePackage() . '/dist/assets/board-index-4c1d2a.js', $published . '/assets/board-index-4c1d2a.js');
        $this->assertFileExists($published . '/assets/showcase-8f3d2c.css');

        $bundles = $this->app->make(SolutionCache::class)->bundles();

        $this->assertNotNull($bundles, 'the cache must be fresh and hold the bundles');
        $this->assertSame($hash, $bundles['showcase']->hash);
        $this->assertSame('assets/board-index-4c1d2a.js', $bundles['showcase']->pages['Board/Index']);
        $this->assertSame(['assets/showcase-8f3d2c.css'], $bundles['showcase']->css);
    }

    public function test_validate_only_checks_the_ui_but_publishes_nothing(): void
    {
        $this->installShowcase(publish: false);

        $this->artisan('platform:update', ['--validate-only' => true])->assertExitCode(0);

        $this->assertDirectoryDoesNotExist(public_path('solutions'));
    }

    public function test_a_bundle_built_for_another_kit_is_a_warning_not_a_failure(): void
    {
        $package = $this->copyPackage(static fn (array $ui): array => ['kit' => '^2.0'] + $ui);
        $this->installShowcase(publish: false, package: $package);

        $this->artisan('platform:update', ['--validate-only' => true])
            ->expectsOutputToContain('showcase: console UI is built for kit ^2.0, this platform ships')
            ->assertExitCode(0);
    }

    public function test_a_broken_description_stops_the_update_before_migrations(): void
    {
        $package = $this->copyPackage(static fn (array $ui): array => ['kit' => '', 'x-ok' => 1, 'extra' => true] + $ui);
        $this->installShowcase(publish: false, package: $package);

        $this->artisan('platform:update')
            ->expectsOutputToContain('showcase: fapost-ui.json: kit: must be a non-empty version constraint')
            ->expectsOutputToContain('showcase: fapost-ui.json: extra: unknown field')
            ->assertExitCode(1);

        $this->assertSame(0, $this->migrations);
        $this->assertDirectoryDoesNotExist(public_path('solutions'));
        $this->assertFileDoesNotExist($this->app->make(SolutionCache::class)->path());
    }

    public function test_a_listed_file_that_is_missing_stops_the_update(): void
    {
        $package = $this->copyPackage(static fn (array $ui): array => $ui);
        unlink($package . '/dist/assets/widgets-stats-5a2f08.js');
        $this->installShowcase(publish: false, package: $package);

        $this->artisan('platform:update')
            ->expectsOutputToContain('showcase: fapost-ui.json: components.Widgets/Stats: "assets/widgets-stats-5a2f08.js" does not exist')
            ->assertExitCode(1);

        $this->assertSame(0, $this->migrations);
    }

    public function test_a_component_declared_in_php_but_not_listed_stops_the_update(): void
    {
        $package = $this->copyPackage(static function (array $ui): array {
            unset($ui['components']['Widgets/Stats']);

            return $ui;
        });
        $this->installShowcase(publish: false, package: $package);

        $this->artisan('platform:update')
            ->expectsOutputToContain('showcase: fapost-ui.json: components: "showcase::Widgets/Stats" is declared in PHP but not listed')
            ->assertExitCode(1);

        $this->assertSame(0, $this->migrations);
    }

    public function test_a_missing_description_file_stops_the_update(): void
    {
        $package = $this->copyPackage(static fn (array $ui): array => $ui);
        unlink($package . '/dist/fapost-ui.json');
        $this->installShowcase(publish: false, package: $package);

        $this->artisan('platform:update')
            ->expectsOutputToContain('showcase: extra.fapost.ui: "dist/fapost-ui.json" does not exist')
            ->assertExitCode(1);
    }

    public function test_only_static_files_of_the_bundle_directory_reach_the_web_root(): void
    {
        $package = $this->copyPackage(static fn (array $ui): array => $ui);
        file_put_contents($package . '/dist/.htaccess', 'Options +ExecCGI');
        file_put_contents($package . '/dist/notes.txt', 'internal');
        file_put_contents($package . '/dist/assets/icon.svg', '<svg onload="alert(1)"/>');
        file_put_contents($package . '/dist/assets/font.woff2', 'font');
        file_put_contents($package . '/dist/assets/.secret.js', 'secret');
        mkdir($package . '/dist/.git/sub', 0o777, true);
        file_put_contents($package . '/dist/.git/sub/a.js', 'tooling');
        // PHP under a hidden path is left behind with the rest of it, not refused.
        file_put_contents($package . '/dist/.git/hook.php', '<?php');
        $this->installShowcase(publish: false, package: $package);

        // Visible files left behind are named, so an import of one is not a mystery 404; hidden ones are not.
        $this->artisan('platform:update')
            ->expectsOutputToContain('showcase: fapost-ui.json: assets/icon.svg is not published (allowed: js, mjs, css')
            ->expectsOutputToContain('showcase: fapost-ui.json: notes.txt is not published')
            ->doesntExpectOutputToContain('.secret.js')
            ->assertExitCode(0);

        $hash      = mb_substr(hash_file('sha256', $package . '/dist/fapost-ui.json'), 0, 12);
        $published = public_path("solutions/showcase/{$hash}");

        $this->assertFileExists($published . '/fapost-ui.json');
        $this->assertFileExists($published . '/assets/font.woff2');
        $this->assertFileDoesNotExist($published . '/.htaccess');
        $this->assertFileDoesNotExist($published . '/notes.txt');
        $this->assertFileDoesNotExist($published . '/assets/icon.svg');
        $this->assertFileDoesNotExist($published . '/assets/.secret.js');
        $this->assertDirectoryDoesNotExist($published . '/.git');
    }

    public function test_a_listed_file_publication_cannot_reach_stops_the_update(): void
    {
        $package = $this->copyPackage(static fn (array $ui): array => $ui);
        // `assets` becomes a link to a directory inside the bundle: the files resolve, but publication never enters a link.
        rename($package . '/dist/assets', $package . '/dist/built');
        symlink($package . '/dist/built', $package . '/dist/assets');
        $this->installShowcase(publish: false, package: $package);

        $this->artisan('platform:update')
            ->expectsOutputToContain('showcase: fapost-ui.json: pages.Board/Index: "assets/board-index-4c1d2a.js" cannot be published')
            ->assertExitCode(1);

        $this->assertSame(0, $this->migrations);
        $this->assertDirectoryDoesNotExist(public_path('solutions'));
    }

    public function test_php_anywhere_in_a_file_name_stops_the_update(): void
    {
        foreach (['assets/x.php.js', 'assets/x.PHTML.css', 'assets/y.Php7'] as $file) {
            $package = $this->copyPackage(static fn (array $ui): array => $ui);
            file_put_contents($package . '/dist/' . $file, 'x');
            $this->installShowcase(publish: false, package: $package);

            $this->artisan('platform:update')
                ->expectsOutputToContain(sprintf('showcase: fapost-ui.json: "%s" is PHP', $file))
                ->assertExitCode(1);
        }

        $this->assertSame(0, $this->migrations);
        $this->assertDirectoryDoesNotExist(public_path('solutions'));
    }

    public function test_php_in_the_bundle_directory_stops_the_update(): void
    {
        $package = $this->copyPackage(static fn (array $ui): array => $ui);
        file_put_contents($package . '/dist/assets/shell.php', '<?php echo 1;');
        $this->installShowcase(publish: false, package: $package);

        $this->artisan('platform:update')
            ->expectsOutputToContain('showcase: fapost-ui.json: "assets/shell.php" is PHP')
            ->assertExitCode(1);

        $this->assertSame(0, $this->migrations);
        $this->assertDirectoryDoesNotExist(public_path('solutions'));
    }

    public function test_a_link_out_of_the_bundle_directory_stops_the_update(): void
    {
        $package = $this->copyPackage(static fn (array $ui): array => $ui);
        symlink($package . '/composer.json', $package . '/dist/assets/leak.js');
        $this->installShowcase(publish: false, package: $package);

        $this->artisan('platform:update')
            ->expectsOutputToContain('showcase: fapost-ui.json: "assets/leak.js" is a link that leads outside the bundle directory')
            ->assertExitCode(1);

        $this->assertSame(0, $this->migrations);
    }

    public function test_a_bundle_at_the_package_root_stops_the_update(): void
    {
        $package = $this->copyPackage(static fn (array $ui): array => $ui);
        $files   = new Filesystem();
        $files->copyDirectory($package . '/dist', $package);
        $files->deleteDirectory($package . '/dist');
        $composer                          = json_decode((string) file_get_contents($package . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $composer['extra']['fapost']['ui'] = 'fapost-ui.json';
        file_put_contents($package . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->installShowcase(publish: false, package: $package);

        $this->artisan('platform:update')
            ->expectsOutputToContain('showcase: extra.fapost.ui: "fapost-ui.json" sits at the package root')
            ->assertExitCode(1);

        $this->assertSame(0, $this->migrations);
    }

    public function test_pruning_ignores_what_is_not_a_build_and_removes_leftover_copies(): void
    {
        $this->installShowcase(publish: false);
        $root = public_path('solutions/showcase');
        mkdir($root . '/0123456789ab.1a2b3c4d.tmp', 0o777, true);
        mkdir($root . '/aaaaaaaaaaaa', 0o777, true);
        mkdir($root . '/not-a-build', 0o777, true);
        touch($root . '/aaaaaaaaaaaa', time() - 100);
        // A leftover copy newer than the previous build must not take its place.
        touch($root . '/0123456789ab.1a2b3c4d.tmp', time() - 10);

        $this->artisan('platform:update')->assertExitCode(0);

        $kept = array_map('basename', (new Filesystem())->directories($root));
        sort($kept);
        $hash     = mb_substr(hash_file('sha256', self::showcasePackage() . '/dist/fapost-ui.json'), 0, 12);
        $expected = [$hash, 'aaaaaaaaaaaa', 'not-a-build'];
        sort($expected);

        $this->assertSame($expected, $kept);
    }

    public function test_the_current_and_the_previous_build_are_kept_and_older_ones_removed(): void
    {
        $package = $this->copyPackage(static fn (array $ui): array => ['x-build' => 1] + $ui);
        $this->installShowcase(publish: false, package: $package);

        $hashes = [];

        foreach ([1, 2, 3] as $build) {
            $this->rewriteUi($package, static fn (array $ui): array => ['x-build' => $build] + $ui);
            $this->artisan('platform:update')->assertExitCode(0);

            $hashes[] = mb_substr(hash_file('sha256', $package . '/dist/fapost-ui.json'), 0, 12);
            // Builds a second apart, as the publisher tells them apart by time.
            touch(public_path('solutions/showcase/' . end($hashes)), time() - 10 + $build);
        }

        $kept = array_map('basename', (new Filesystem())->directories(public_path('solutions/showcase')));
        sort($kept);
        $expected = [$hashes[1], $hashes[2]];
        sort($expected);

        $this->assertCount(3, array_unique($hashes));
        $this->assertSame($expected, $kept);
    }

    public function test_solutions_cache_records_what_is_published_and_a_rebuild_is_not_taken_for_it(): void
    {
        $package = $this->copyPackage(static fn (array $ui): array => $ui);
        $this->installShowcase(package: $package);

        $this->artisan('solutions:cache')->assertExitCode(0);
        $this->assertSame(['showcase'], array_keys($this->app->make(SolutionCache::class)->bundles() ?? []));

        // A new build that nobody published yet: the cache goes stale, and the new hash has no directory.
        $this->rewriteUi($package, static fn (array $ui): array => ['x-build' => 2] + $ui);
        clearstatcache();

        $this->assertNull($this->app->make(SolutionCache::class)->bundles());
        $this->app->forgetInstance(PublishedSolutionUi::class);
        $this->assertSame([], $this->app->make(PublishedSolutionUi::class)->all());
    }

    /**
     * A copy of the fixture package with its `fapost-ui.json` changed.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function copyPackage(callable $change): string
    {
        $copy = sys_get_temp_dir() . '/fapost-showcase-' . bin2hex(random_bytes(6));
        (new Filesystem())->copyDirectory(self::showcasePackage(), $copy);
        $this->copies[] = $copy;
        $this->rewriteUi($copy, $change);

        return $copy;
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function rewriteUi(string $package, callable $change): void
    {
        $file = $package . '/dist/fapost-ui.json';
        $ui   = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        file_put_contents($file, json_encode($change($ui), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
