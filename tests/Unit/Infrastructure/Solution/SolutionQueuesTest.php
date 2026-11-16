<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Solution;

use App\Domains\Solution\Services\InstalledSolutions;
use App\Infrastructure\Solution\SolutionQueues;
use App\Jobs\Solution\PrepareSolutionSchemaJob;
use App\Providers\HorizonServiceProvider;
use Tests\Support\InstallsMigratingSolutions;
use Tests\TestCase;

/**
 * The queue of every installed Solution and the Horizon supervisor that serves them: filled from the image's catalog,
 * and gone when the catalog is empty, because Horizon would give a supervisor with no queues the `default` queue.
 */
final class SolutionQueuesTest extends TestCase
{
    use InstallsMigratingSolutions;

    protected function setUp(): void
    {
        parent::setUp();

        // The configuration as the file holds it, before any Solution list was put in.
        config(['horizon' => require base_path('config/horizon.php')]);
    }

    protected function tearDown(): void
    {
        $this->removeMigratingSolutions();

        parent::tearDown();
    }

    public function test_the_queue_name_is_the_prefix_and_the_solution_id(): void
    {
        $this->assertSame('solution-jobs.feedback', SolutionQueues::nameFor('feedback'));
        $this->assertSame('redis-long', SolutionQueues::CONNECTION);
    }

    public function test_there_is_one_queue_per_installed_solution(): void
    {
        $queues = new SolutionQueues($this->install('notes', 'chat'));

        $this->assertEqualsCanonicalizing(['solution-jobs.notes', 'solution-jobs.chat'], $queues->names());
    }

    public function test_there_are_no_queues_without_solutions(): void
    {
        $this->assertSame([], (new SolutionQueues($this->install()))->names());
    }

    public function test_horizon_serves_the_queue_of_each_installed_solution(): void
    {
        (new SolutionQueues($this->install('notes', 'chat')))->configureHorizon();

        $queues = (array) config('horizon.defaults.solution-jobs.queue');

        $this->assertEqualsCanonicalizing(['solution-jobs.notes', 'solution-jobs.chat'], $queues);
        $this->assertSame('redis-long', config('horizon.defaults.solution-jobs.connection'));
        $this->assertSame('auto', config('horizon.defaults.solution-jobs.balance'));
        $this->assertNotContains('default', $queues);

        foreach ((array) config('horizon.environments') as $environment => $supervisors) {
            $this->assertArrayHasKey('solution-jobs', $supervisors, 'environment ' . $environment);
        }
    }

    public function test_the_process_ceiling_grows_with_the_queues_in_the_defaults_and_every_environment(): void
    {
        foreach ([1, 3] as $count) {
            config(['horizon' => require base_path('config/horizon.php')]);
            $ids = array_slice(['notes', 'chat', 'mail'], 0, $count);

            (new SolutionQueues($this->install(...$ids)))->configureHorizon();

            $this->assertSame($count + 1, config('horizon.defaults.solution-jobs.maxProcesses'));

            foreach ((array) config('horizon.environments') as $environment => $supervisors) {
                $this->assertSame($count + 1, $supervisors['solution-jobs']['maxProcesses'], 'environment ' . $environment);
            }
        }
    }

    public function test_an_environment_without_the_supervisor_does_not_get_one(): void
    {
        $environments = (array) config('horizon.environments');
        $first        = (string) array_key_first($environments);
        unset($environments[$first]['solution-jobs']);
        config(['horizon.environments' => $environments]);

        (new SolutionQueues($this->install('notes')))->configureHorizon();

        $this->assertArrayNotHasKey('solution-jobs', (array) config('horizon.environments.' . $first));
    }

    public function test_without_solutions_the_supervisor_is_removed_from_the_defaults_and_every_environment(): void
    {
        $environments = array_keys((array) config('horizon.environments'));
        $this->assertNotEmpty($environments);
        $this->assertArrayHasKey(SolutionQueues::SUPERVISOR, (array) config('horizon.defaults'));

        (new SolutionQueues($this->install()))->configureHorizon();

        $this->assertArrayNotHasKey(SolutionQueues::SUPERVISOR, (array) config('horizon.defaults'));

        foreach ($environments as $environment) {
            $supervisors = (array) config('horizon.environments.' . $environment);

            $this->assertArrayNotHasKey(SolutionQueues::SUPERVISOR, $supervisors, 'environment ' . $environment);
            $this->assertNotEmpty($supervisors, 'The other supervisors of ' . $environment . ' are kept.');
        }

        $this->assertArrayHasKey('solutions', (array) config('horizon.defaults'), 'The other supervisors are kept.');
    }

    public function test_no_solution_queue_is_the_one_core_prepares_schemas_on(): void
    {
        $this->assertNotContains(PrepareSolutionSchemaJob::QUEUE, (new SolutionQueues($this->install('migrate')))->names());
    }

    public function test_configuring_twice_gives_the_same_result(): void
    {
        $queues = new SolutionQueues($this->install('notes'));

        $queues->configureHorizon();
        $queues->configureHorizon();

        $this->assertSame(['solution-jobs.notes'], config('horizon.defaults.solution-jobs.queue'));
    }

    public function test_booting_horizon_fills_the_queues_from_the_catalog(): void
    {
        $this->install('notes');

        (new HorizonServiceProvider($this->app))->boot();

        $this->assertSame(['solution-jobs.notes'], config('horizon.defaults.solution-jobs.queue'));
    }

    public function test_booting_horizon_without_solutions_removes_the_supervisor(): void
    {
        $this->install();

        (new HorizonServiceProvider($this->app))->boot();

        $this->assertArrayNotHasKey(SolutionQueues::SUPERVISOR, (array) config('horizon.defaults'));
    }

    private function install(string ...$ids): InstalledSolutions
    {
        return $this->installMigratingSolutions(array_fill_keys($ids, []));
    }
}
