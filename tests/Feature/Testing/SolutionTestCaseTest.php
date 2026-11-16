<?php

declare(strict_types=1);

namespace Tests\Feature\Testing;

use Acme\Feedback\RecordFeedbackJob;
use App\Domains\Solution\Providers\SolutionServiceProvider;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use ArrayObject;
use Fapost\Foundation\Solution\Queue\SolutionQueueInterface;
use Fapost\Testing\ActionCallResult;
use Fapost\Testing\PackageAutoloader;
use Fapost\Testing\SolutionTestCase;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Bus;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Tests\Fixtures\Solutions\Showcase\ShowcasePingAction;
use Tests\Support\FakeVendor;
use Tests\Support\ForeignSolutionJob;
use Tests\Support\InstallsFeedbackSolution;
use Tests\Support\InstallsShowcaseSolution;
use Throwable;

/**
 * The Solution testing kit proven against a fixture Solution that has both an action and a console page: a copy of the
 * showcase package whose manifest also declares `showcase.ping`.
 *
 * Core's own helpers install the fixture here; the kit methods under test are used exactly as a Solution's test uses them.
 */
final class SolutionTestCaseTest extends SolutionTestCase
{
    use InstallsFeedbackSolution;
    use InstallsShowcaseSolution;

    /** @var list<string> */
    private array $scratch = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->installShowcase(
            publish: false,
            package: $this->packageWithAction(),
            prepareVendor: static fn (FakeVendor $vendor) => self::linkFeedback($vendor),
        );

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

    public function test_a_queued_solution_job_is_asserted_and_then_run_in_its_tenant_through_core_s_middleware(): void
    {
        $tenant = $this->mainTenant();
        $this->createFeedbackJobLog($tenant);
        $this->activateSolution('feedback');

        $this->queueFeedbackJob($tenant, 'first');
        $this->queueFeedbackJob($tenant, 'second');

        $this->assertSolutionJobQueued(RecordFeedbackJob::class);
        $this->assertSolutionJobQueued(
            RecordFeedbackJob::class,
            static fn (RecordFeedbackJob $job): bool => 'second' === $job->title && 'solution-jobs.feedback' === $job->queue && $job->tenantId === $tenant->getId(),
        );
        $this->assertSame([], $this->feedbackJobLog($tenant), 'Nothing runs before the test says so.');

        $this->assertSame(2, $this->runQueuedSolutionJobs());
        $this->assertSame(['first', 'second'], $this->feedbackJobLog($tenant));

        $this->assertSame(0, $this->runQueuedSolutionJobs(), 'A job is run once.');
        $this->assertSame(['first', 'second'], $this->feedbackJobLog($tenant));

        $this->queueFeedbackJob($tenant, 'third');
        $this->assertSame(1, $this->runQueuedSolutionJobs());
        $this->assertSame(['first', 'second', 'third'], $this->feedbackJobLog($tenant));
    }

    public function test_a_queued_job_of_a_solution_that_is_not_active_is_dropped_by_the_middleware_when_run(): void
    {
        $tenant = $this->mainTenant();
        $this->createFeedbackJobLog($tenant);

        $this->queueFeedbackJob($tenant, 'unwanted');

        $this->assertSame(1, $this->runQueuedSolutionJobs());
        $this->assertSame([], $this->feedbackJobLog($tenant));
    }

    public function test_the_queue_assertion_fails_for_a_job_that_was_not_queued_or_does_not_match(): void
    {
        $tenant = $this->mainTenant();

        foreach ([
            fn () => $this->assertSolutionJobQueued(RecordFeedbackJob::class),
            function () use ($tenant): void {
                $this->queueFeedbackJob($tenant, 'one');
                $this->assertSolutionJobQueued(RecordFeedbackJob::class, static fn (RecordFeedbackJob $job): bool => 'other' === $job->title);
            },
            fn () => $this->assertSolutionJobQueued(ForeignSolutionJob::class),
        ] as $check) {
            try {
                $check();
                $this->fail('The assertion should have failed.');
            } catch (AssertionFailedError $e) {
                $this->assertStringNotContainsString('should have failed', $e->getMessage());
            }
        }
    }

    public function test_a_solution_job_dispatched_around_the_solution_queue_fails_at_the_dispatch(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('queued without a tenant');

        $this->app->make(Dispatcher::class)->dispatch(new RecordFeedbackJob('around'));
    }

    public function test_the_queued_job_is_a_serialized_and_restored_copy_and_one_that_cannot_be_serialized_fails(): void
    {
        $tenant   = $this->mainTenant();
        $original = new RecordFeedbackJob('copy');
        $this->app->make(TenantSwitcher::class)->runForTenant($tenant, fn () => $this->app->make(SolutionQueueInterface::class)->dispatch($original));

        $this->assertSolutionJobQueued(RecordFeedbackJob::class, static fn (RecordFeedbackJob $job): bool => $job !== $original && 'copy' === $job->title);

        // Handed to the bus with a closure inside, as the dispatcher would have refused: a worker could not read it.
        $broken = new RecordFeedbackJob('broken', static fn (): int => 1);
        $broken->forTenant($tenant->getId());

        $this->expectException(Throwable::class);
        $this->expectExceptionMessage('Closure');

        $this->app->make(Dispatcher::class)->dispatch($broken);
    }

    public function test_jobs_are_found_whatever_the_test_fakes_on_the_bus(): void
    {
        $tenant = $this->mainTenant();

        Bus::fake([ForeignSolutionJob::class]);
        $this->queueFeedbackJob($tenant, 'beside another fake');
        $this->assertSolutionJobQueued(RecordFeedbackJob::class, static fn (RecordFeedbackJob $job): bool => 'beside another fake' === $job->title);

        Bus::fake();
        $this->queueFeedbackJob($tenant, 'under a full fake');
        $this->assertSolutionJobQueued(RecordFeedbackJob::class, static fn (RecordFeedbackJob $job): bool => 'under a full fake' === $job->title);
        $this->assertSolutionJobQueued(RecordFeedbackJob::class, static fn (RecordFeedbackJob $job): bool => 'beside another fake' === $job->title);
    }

    public function test_other_jobs_still_reach_the_real_dispatcher(): void
    {
        $ran = new ArrayObject();

        $this->app->make(Dispatcher::class)->dispatchSync(new class ($ran) {
            public function __construct(private readonly ArrayObject $ran)
            {
            }

            public function handle(): void
            {
                $this->ran->append('ran');
            }
        });

        $this->assertSame(['ran'], $ran->getArrayCopy());
        $this->assertSame(0, $this->runQueuedSolutionJobs());
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

    private function mainTenant(): TenantInterface
    {
        $tenant = $this->app->make(TenantRepositoryInterface::class)->findBySlug('main');
        $this->assertNotNull($tenant);

        return $tenant;
    }

    private function queueFeedbackJob(TenantInterface $tenant, string $title): void
    {
        $this->app->make(TenantSwitcher::class)->runForTenant(
            $tenant,
            fn () => $this->app->make(SolutionQueueInterface::class)->dispatch(new RecordFeedbackJob($title)),
        );
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
