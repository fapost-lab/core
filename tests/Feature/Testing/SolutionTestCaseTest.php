<?php

declare(strict_types=1);

namespace Tests\Feature\Testing;

use App\Domains\Solution\Providers\SolutionServiceProvider;
use App\Domains\Solution\Services\InstalledSolutions;
use Fapost\Testing\ActionCallResult;
use Fapost\Testing\PackageAutoloader;
use Fapost\Testing\SolutionTestCase;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Tests\Fixtures\Solutions\Showcase\ShowcasePingAction;
use Tests\Support\InstallsShowcaseSolution;

/**
 * The Solution testing kit proven against a fixture Solution that has both an action and a console page: a copy of the
 * showcase package whose manifest also declares `showcase.ping`.
 *
 * Core's own helpers install the fixture here; the kit methods under test are used exactly as a Solution's test uses them.
 */
final class SolutionTestCaseTest extends SolutionTestCase
{
    use InstallsShowcaseSolution;

    /** @var list<string> */
    private array $scratch = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->installShowcase(publish: false, package: $this->packageWithAction());

        // What Core does at startup: give the provider its registrar, which registers the action handler.
        (new SolutionServiceProvider($this->app))->registerExtensions($this->app->make(InstalledSolutions::class)->all());
    }

    protected function tearDown(): void
    {
        $this->removeShowcase();

        foreach ($this->scratch as $directory) {
            (new Filesystem())->deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_the_page_is_not_found_before_activation(): void
    {
        $this->publishSolutionUi('showcase');

        $this->actingAsStaff()->getConsole('/apps/showcase/board')->assertNotFound();
    }

    public function test_the_page_is_served_once_activated_and_not_found_after_deactivation(): void
    {
        $this->publishSolutionUi('showcase');
        $this->activateSolution('showcase');

        $this->assertSolutionPage(
            $this->actingAsStaff()->getConsole('/apps/showcase/board'),
            'showcase::Board/Index',
            fn ($page) => $page->where('title', 'Board'),
        );

        $this->deactivateSolution('showcase');

        $this->getConsole('/apps/showcase/board')->assertNotFound();
    }

    public function test_the_published_bundle_lands_in_the_throwaway_public_path(): void
    {
        $this->publishSolutionUi('showcase');

        $this->assertDirectoryExists($this->app->publicPath() . '/solutions/showcase');
        $this->assertStringStartsWith(sys_get_temp_dir(), $this->app->publicPath());
    }

    public function test_publishing_an_unknown_solution_fails(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->publishSolutionUi('nobody');
    }

    public function test_an_action_is_refused_while_inactive_and_succeeds_while_active(): void
    {
        $this->callAction('showcase.ping', ['word' => 'hi'])->assertRefused('solution_inactive');

        $this->activateSolution('showcase');

        $result = $this->callAction('showcase.ping', ['word' => 'hi'])->assertSucceeded();

        $this->assertSame(['pong' => 'hi'], $result->body);
    }

    public function test_the_action_is_registered_for_the_solution_that_declares_it(): void
    {
        $this->assertActionRegistered('showcase.ping', ShowcasePingAction::class);
    }

    public function test_a_wrong_handler_class_fails_the_registration_assertion(): void
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertActionRegistered('showcase.ping', self::class);
    }

    public function test_the_result_assertions_fail_when_the_outcome_differs(): void
    {
        $refused = new ActionCallResult(false, null, 'solution_inactive');
        $ok      = ActionCallResult::fromArray(['success' => true, 'body' => ['a' => 1], 'error_code' => null]);

        $this->assertSame($refused, $refused->assertRefused('solution_inactive'));
        $this->assertSame($ok, $ok->assertSucceeded());
        $this->assertSame(['a' => 1], $ok->body);

        foreach ([
            static fn () => $refused->assertSucceeded(),
            static fn () => $refused->assertRefused('limit_reached'),
            static fn () => $ok->assertRefused('solution_inactive'),
        ] as $check) {
            try {
                $check();
                $this->fail('The assertion should have failed.');
            } catch (AssertionFailedError $e) {
                $this->assertStringNotContainsString('should have failed', $e->getMessage());
            }
        }
    }

    public function test_the_package_autoloader_maps_both_psr4_sections_ahead_of_other_loaders(): void
    {
        $root = sys_get_temp_dir() . '/fapost-pkg-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        mkdir($root . '/tests', 0o777, true);
        $this->scratch[] = $root;

        file_put_contents($root . '/composer.json', json_encode([
            'autoload'     => ['psr-4' => ['Kit\\Probe\\' => 'src/']],
            'autoload-dev' => ['psr-4' => ['Kit\\Probe\\Tests\\' => 'tests/']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/src/Thing.php', '<?php namespace Kit\Probe; final class Thing {}');
        file_put_contents($root . '/tests/Helper.php', '<?php namespace Kit\Probe\Tests; final class Helper {}');

        PackageAutoloader::register($root);

        $this->assertTrue(class_exists('Kit\\Probe\\Thing'));
        $this->assertTrue(class_exists('Kit\\Probe\\Tests\\Helper'));
        $this->assertFalse(class_exists('Kit\\Probe\\Missing'));
    }

    public function test_the_package_autoloader_requires_a_composer_json(): void
    {
        $this->expectException(RuntimeException::class);

        PackageAutoloader::register(sys_get_temp_dir() . '/fapost-no-such-package');
    }

    /**
     * A copy of the showcase package that also declares and registers the `showcase.ping` action.
     */
    private function packageWithAction(): string
    {
        $copy = sys_get_temp_dir() . '/fapost-showcase-ping-' . bin2hex(random_bytes(6));
        (new Filesystem())->copyDirectory(self::showcasePackage(), $copy);
        $this->scratch[] = $copy;

        $composer                               = json_decode((string) file_get_contents($copy . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $composer['extra']['fapost']['actions'] = ['showcase.ping'];
        file_put_contents($copy . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $copy;
    }
}
