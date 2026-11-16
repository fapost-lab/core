<?php

declare(strict_types=1);

namespace Fapost\Testing;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Call\CallTester;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\SolutionUiPublisher;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Support\TenantHost;
use Closure;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Solution\Queue\SolutionJob;
use Fapost\Testing\Concerns\IsolatesLandlordPerParallelProcess;
use Fapost\Testing\Concerns\PreparesCoreDatabase;
use Fapost\Testing\Support\SolutionJobBus;
use Fapost\Testing\Support\SolutionRequestState;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Testing\Fakes\BusFake;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/**
 * Base class for the tests of a Solution package.
 *
 * It boots the real Core application (the test always runs inside a Core checkout the package is installed into), puts
 * the same database in place as Core's own feature tests ({@see PreparesCoreDatabase}) and offers the few things a
 * Solution test does: publish its UI bundle, switch it on for a tenant, sign in as staff, open a console page and
 * call an action, and assert and run a job it queued. A test using only this class, `Fapost\Foundation\…` and Laravel needs no import from `App\…`.
 *
 * @experimental may change in minor versions before 1.0
 */
abstract class SolutionTestCase extends BaseTestCase
{
    use IsolatesLandlordPerParallelProcess;
    use PreparesCoreDatabase;

    private string $publicPath = '';

    private bool $aclSeeded = false;

    private ?SolutionJobBus $solutionBus = null;

    /** @var list<SolutionJob> */
    private array $ranSolutionJobs = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Pages are rendered without a Vite manifest: a test asserts on the response, not on compiled assets.
        $this->withoutVite();

        // Publishing a bundle writes into the web root; a throwaway one keeps Core's own public/ untouched.
        $this->publicPath = sys_get_temp_dir() . '/fapost-solution-test-' . bin2hex(random_bytes(6));
        mkdir($this->publicPath, 0o777, true);
        $this->app->usePublicPath($this->publicPath);
        $this->aclSeeded = false;

        // Solution jobs are kept instead of sent to a queue; every other job goes where it would have gone.
        $this->solutionBus = new SolutionJobBus($this->app, fn (?string $connection = null) => $this->app->make(QueueFactory::class)->connection($connection));
        Bus::swap($this->solutionBus);
        $this->ranSolutionJobs = [];
    }

    protected function tearDown(): void
    {
        if ('' !== $this->publicPath) {
            (new Filesystem())->deleteDirectory($this->publicPath);
            $this->publicPath = '';
        }

        parent::tearDown();
    }

    public function createApplication(): Application
    {
        $app = require dirname(__DIR__) . '/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Validates and publishes the UI bundle of one installed Solution.
     *
     * Other installed Solutions are not this package's business: only problems reported for `$id` fail the test.
     */
    protected function publishSolutionUi(string $id): void
    {
        $publisher = $this->app->make(SolutionUiPublisher::class);
        $check     = $publisher->check($this->app->make(InstalledSolutions::class)->all());

        $ownProblems = array_values(array_filter(
            $check['problems'],
            static fn (string $problem): bool => str_starts_with($problem, "{$id}:"),
        ));

        $this->assertSame([], $ownProblems, "The UI bundle of Solution '{$id}' has problems.");
        $this->assertArrayHasKey($id, $check['sources'], "Solution '{$id}' has no UI bundle to publish.");

        $publisher->publish([$id => $check['sources'][$id]]);
        $this->forgetRequestState();
    }

    protected function activateSolution(string $id, string $tenant = 'main'): void
    {
        $this->artisan('solutions:activate', ['solution' => $id, '--tenant' => $tenant])->assertExitCode(0);
        $this->forgetRequestState();
    }

    protected function deactivateSolution(string $id, string $tenant = 'main'): void
    {
        $this->artisan('solutions:deactivate', ['solution' => $id, '--tenant' => $tenant])->assertExitCode(0);
        $this->forgetRequestState();
    }

    /**
     * Signs in a staff member of the default tenant.
     *
     * @param  string  $role  `admin`, `content_manager` or `analyst`
     */
    protected function actingAsStaff(string $role = 'admin'): static
    {
        if (! $this->aclSeeded) {
            $this->seed(TenantAclSeeder::class);
            $this->aclSeeded = true;
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $this->actingAs($user);
    }

    /**
     * GET a console path on the tenant host: console routes are bound to it, a bare path answers 404.
     */
    protected function getConsole(string $path): TestResponse
    {
        $host = TenantHost::forDefaultTenant();
        $url  = null === $host ? $path : 'http://' . $host . '/' . mb_ltrim($path, '/');

        return $this->get($url);
    }

    /**
     * Asserts a Solution console page. A Solution page is a prebuilt module, not a file of Core's pages directory,
     * so the component file check is switched off.
     *
     * @param  (Closure(AssertableInertia): mixed)|null  $props  further assertions on the page
     */
    protected function assertSolutionPage(TestResponse $response, string $component, ?Closure $props = null): void
    {
        $response->assertOk();
        $response->assertInertia(static function (AssertableInertia $page) use ($component, $props): void {
            $page->component($component, false);

            if (null !== $props) {
                $props($page);
            }
        });
    }

    /**
     * Calls an action as a flow's `call` node would, through the handler transport and inside the tenant.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function callAction(string $action, array $parameters = [], string $tenant = 'main'): ActionCallResult
    {
        $runtimeTenant = $this->app->make(TenantRepositoryInterface::class)->findBySlug($tenant);
        $this->assertNotNull($runtimeTenant, "Tenant '{$tenant}' does not exist.");

        $result = $this->app->make(TenantSwitcher::class)->runForTenant(
            $runtimeTenant,
            fn (): array => $this->app->make(CallTester::class)->run(
                ['transport' => 'handler', 'target' => $action, 'parameters' => $parameters],
                [],
            ),
        );

        return ActionCallResult::fromArray($result);
    }

    /**
     * Asserts the action is registered, owned by the Solution whose manifest declares it, and built from `$handlerClass`.
     *
     * @param  class-string  $handlerClass
     */
    protected function assertActionRegistered(string $action, string $handlerClass): void
    {
        $registry = $this->app->make(ActionHandlerRegistry::class);

        $this->assertTrue($registry->has($action), "Action '{$action}' is not registered.");

        $declaredBy = null;

        foreach ($this->app->make(InstalledSolutions::class)->all() as $solution) {
            if (in_array($action, $solution->actions, true)) {
                $declaredBy = $solution->id;

                break;
            }
        }

        $this->assertNotNull($declaredBy, "No installed Solution declares action '{$action}' in its manifest.");
        $this->assertSame($declaredBy, $registry->ownerOf($action), "Action '{$action}' is registered by another Solution than the one declaring it.");
        $this->assertInstanceOf($handlerClass, $registry->get($action));
    }

    /**
     * Asserts a job of the given class was queued through the Solution queue and, when a callback is given, that one of
     * them satisfies it (the callback gets the job and returns a bool).
     *
     * Jobs are kept, not sent: the kit puts its own dispatcher in place of Laravel's bus, which keeps Solution jobs (as a
     * serialized and restored copy, as a worker would get them) and passes every other job on. A `Bus::fake()` of your own
     * wraps it: with `Bus::fake([Other::class])` Solution jobs still reach the kit's; with a plain `Bus::fake()` the fake
     * takes them and they are found there. A Solution job dispatched without `SolutionQueueInterface` fails the test at
     * the dispatch.
     *
     * @template TJob of SolutionJob
     *
     * @param  class-string<TJob>  $jobClass
     * @param  (Closure(TJob): bool)|null  $callback
     */
    protected function assertSolutionJobQueued(string $jobClass, ?Closure $callback = null): void
    {
        $queued = array_values(array_filter(
            $this->queuedSolutionJobs(),
            static fn (SolutionJob $job): bool => $job instanceof $jobClass && (null === $callback || $callback($job)),
        ));

        $this->assertNotSame([], $queued, null === $callback
            ? "No Solution job of class {$jobClass} was queued."
            : "No Solution job of class {$jobClass} matching the callback was queued.");
    }

    /**
     * Runs the Solution jobs queued since the last call, the way a worker would, without Horizon: each goes through
     * Core's own job middleware (the access-mode gate, the switch into the job's tenant, the activation and schema
     * checks) and then its `handle()`. A job the gate drops is not run; one it puts back for later is queued again,
     * and a call after this one picks that copy up, ignoring its delay.
     *
     * Scoped services are dropped before each job, as a worker does between jobs. An exception from a job reaches the test.
     *
     * @return int how many jobs were taken through the gate (run, dropped or put back)
     */
    protected function runQueuedSolutionJobs(): int
    {
        $pending = array_values(array_filter(
            $this->queuedSolutionJobs(),
            fn (SolutionJob $job): bool => !in_array($job, $this->ranSolutionJobs, true),
        ));

        foreach ($pending as $job) {
            $this->ranSolutionJobs[] = $job;
            $this->app->forgetScopedInstances();
            $this->forgetRequestState();

            (new Pipeline($this->app))
                ->send($job)
                ->through($job->middleware())
                ->then(fn (SolutionJob $given): mixed => $this->app->call([$given, 'handle']));
        }

        return count($pending);
    }

    /**
     * @return list<SolutionJob>
     */
    private function queuedSolutionJobs(): array
    {
        $jobs = $this->solutionBus?->queuedJobs() ?? [];
        $bus  = Bus::getFacadeRoot();

        if ($bus instanceof BusFake) {
            $commands = Closure::bind(fn (): array => $this->commands, $bus, BusFake::class)();

            foreach ($commands as $dispatched) {
                foreach ($dispatched as $command) {
                    if ($command instanceof SolutionJob) {
                        $jobs[] = $command;
                    }
                }
            }
        }

        return $jobs;
    }

    private function forgetRequestState(): void
    {
        SolutionRequestState::forget($this->app);
    }
}
