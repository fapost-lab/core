<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Solution;

use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantAccessStates;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Infrastructure\Solution\RunsSolutionJob;
use App\Jobs\Solution\PrepareSolutionSchemaJob;
use Fapost\Foundation\Solution\Contracts\SolutionEntitlementInterface;
use Fapost\Foundation\Solution\DTO\SolutionEntitlement;
use Fapost\Foundation\Solution\DTO\SolutionGrant;
use Fapost\Foundation\Solution\Enums\SolutionGrantSource;
use Fapost\Foundation\Solution\Queue\SolutionJob;
use Fapost\Foundation\Solution\Queue\SolutionJobMiddlewareInterface;
use Fapost\Foundation\Solution\Queue\SolutionJobRejectedException;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Fapost\Testing\Support\SolutionRequestState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeSolutionEntitlement;
use Tests\Support\FakeTenantAccessMode;
use Tests\Support\ForeignSolutionJob;
use Tests\Support\InstallsMigratingSolutions;

/**
 * Core's gate in front of every Solution job: what it drops, what it puts back for later and when it lets the job run.
 * The middleware does not look at where a job class lives, so a plain job of an installed Solution stands for any.
 */
final class RunsSolutionJobTest extends FeatureTestCase
{
    use InstallsMigratingSolutions;

    private const string CREATE_NOTES = '2026_11_16_000001_create_notes_table';

    private TenantInterface $tenant;

    private int $ran = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->app->make(TenantRepositoryInterface::class)->findBySlug('main');
        $this->assertNotNull($tenant);
        $this->tenant = $tenant;
        $this->ran    = 0;

        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);
        Bus::fake();
        Log::spy();
    }

    protected function tearDown(): void
    {
        $this->removeMigratingSolutions();

        parent::tearDown();
    }

    public function test_the_container_gives_solution_jobs_this_middleware(): void
    {
        $this->assertInstanceOf(RunsSolutionJob::class, $this->app->make(SolutionJobMiddlewareInterface::class));
        $this->assertInstanceOf(RunsSolutionJob::class, $this->stamped()->middleware()[0]);
    }

    public function test_a_job_queued_around_the_dispatcher_fails_with_the_reason(): void
    {
        $job = new ForeignSolutionJob('notes');

        $this->expectException(SolutionJobRejectedException::class);
        $this->expectExceptionMessage('outside a tenant');

        $this->through($job);
    }

    public function test_a_job_of_a_tenant_that_is_gone_is_dropped(): void
    {
        $job           = $this->stamped();
        $job->tenantId = (string) Str::uuid();

        $this->assertNull($this->through($job));

        $this->assertSame(0, $this->ran);
        $this->assertDropped('tenant_gone');
        Bus::assertNothingDispatched();
    }

    public function test_the_job_of_a_stopped_tenant_does_not_run_and_a_copy_comes_back_in_an_hour(): void
    {
        $this->activateNotes();
        $access = FakeTenantAccessMode::stopped();
        $this->useAccessMode($access);
        $job = $this->stamped();

        $this->through($job);

        $this->assertSame(0, $this->ran);
        $this->assertSame([$this->tenant->getId()], $access->asked);
        $this->assertSame(0, $job->postponements, 'The original is left as it was.');
        Bus::assertDispatchedTimes(ForeignSolutionJob::class, 1);
        Bus::assertDispatched(
            ForeignSolutionJob::class,
            fn (ForeignSolutionJob $copy): bool => $copy !== $job
                && RunsSolutionJob::STOPPED_DELAY_SECONDS === $copy->delay
                && 3600 === $copy->delay
                && 0 === $copy->postponements
                && $copy->tenantId === $this->tenant->getId()
                && 'redis-long' === $copy->connection
                && 'solution-jobs.notes' === $copy->queue,
        );
        $this->assertLogged('solution_job.postponed', 'tenant_stopped');
    }

    public function test_a_resumed_tenant_runs_its_job(): void
    {
        $this->activateNotes();
        $access = FakeTenantAccessMode::stopped();
        $this->useAccessMode($access);
        $this->through($this->stamped());
        $this->assertSame(0, $this->ran);

        $access->resume();
        $this->through($this->stamped());

        $this->assertSame(1, $this->ran);
    }

    public function test_a_solution_that_is_not_active_for_the_tenant_drops_the_job(): void
    {
        $this->assertNull($this->through($this->stamped()));

        $this->assertSame(0, $this->ran);
        $this->assertDropped('solution_inactive');
        Bus::assertNothingDispatched();
    }

    public function test_a_solution_switched_off_after_queueing_drops_the_job(): void
    {
        $this->activateNotes();
        $this->through($this->stamped());
        $this->assertSame(1, $this->ran);

        $this->inTenant(fn () => $this->app->make(SolutionActivator::class)->deactivate('notes', ActivationActor::console()));
        SolutionRequestState::forget($this->app);
        $this->through($this->stamped());

        $this->assertSame(1, $this->ran, 'The second job did not run.');
        $this->assertDropped('solution_inactive');
    }

    public function test_a_solution_whose_tables_are_not_ready_has_them_prepared_and_the_job_comes_back_in_a_minute(): void
    {
        $this->grantNotes();
        $job = $this->stamped();

        $this->through($job);

        $this->assertSame(0, $this->ran);
        Bus::assertDispatched(
            PrepareSolutionSchemaJob::class,
            fn (PrepareSolutionSchemaJob $prepare): bool => $prepare->tenantId === $this->tenant->getId() && 'notes' === $prepare->solutionId,
        );
        Bus::assertDispatched(
            ForeignSolutionJob::class,
            static fn (ForeignSolutionJob $copy): bool => 60 === $copy->delay && 1 === $copy->postponements && $copy !== $job,
        );
        $this->assertLogged('solution_job.postponed', 'solution_not_ready');
    }

    public function test_a_job_put_back_for_the_last_time_is_dropped_instead(): void
    {
        $this->grantNotes();
        $job                = $this->stamped();
        $job->postponements = RunsSolutionJob::MAX_POSTPONEMENTS;

        $this->through($job);

        $this->assertSame(0, $this->ran);
        Bus::assertNotDispatched(ForeignSolutionJob::class);
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => 'solution_job.dropped' === $message
                && 'solution_not_ready' === $context['reason']
                && RunsSolutionJob::MAX_POSTPONEMENTS === $context['postponements']
                && RunsSolutionJob::MAX_POSTPONEMENTS === $context['limit'],
        )->once();
    }

    public function test_the_last_postponement_before_the_limit_is_still_put_back(): void
    {
        $this->grantNotes();
        $job                = $this->stamped();
        $job->postponements = RunsSolutionJob::MAX_POSTPONEMENTS - 1;

        $this->through($job);

        Bus::assertDispatched(ForeignSolutionJob::class, static fn (ForeignSolutionJob $copy): bool => RunsSolutionJob::MAX_POSTPONEMENTS === $copy->postponements);
    }

    public function test_a_stopped_tenants_job_waits_without_a_limit_and_without_counting(): void
    {
        $this->activateNotes();
        $this->useAccessMode(FakeTenantAccessMode::stopped());
        $job                = $this->stamped();
        $job->postponements = RunsSolutionJob::MAX_POSTPONEMENTS;

        $this->through($job);

        $this->assertSame(0, $this->ran);
        Bus::assertDispatchedTimes(ForeignSolutionJob::class, 1);
        Bus::assertDispatched(
            ForeignSolutionJob::class,
            static fn (ForeignSolutionJob $copy): bool => RunsSolutionJob::MAX_POSTPONEMENTS === $copy->postponements && 3600 === $copy->delay,
        );
        Log::shouldNotHaveReceived('warning');
    }

    public function test_on_the_sync_connection_a_stopped_tenants_job_is_dropped(): void
    {
        $this->activateNotes();
        $this->useAccessMode(FakeTenantAccessMode::stopped());
        $job             = $this->stamped();
        $job->connection = 'sync';

        $this->through($job);

        Bus::assertNothingDispatched();
        $this->assertDropped('tenant_stopped');
    }

    public function test_on_the_sync_connection_nothing_is_put_back(): void
    {
        $this->grantNotes();
        $job             = $this->stamped();
        $job->connection = 'sync';

        $this->through($job);

        $this->assertSame(0, $this->ran);
        Bus::assertNotDispatched(ForeignSolutionJob::class);
        $this->assertDropped('solution_not_ready');
    }

    public function test_an_active_and_ready_job_runs_inside_its_tenant_and_the_previous_context_comes_back(): void
    {
        $this->activateNotes();
        $context   = $this->app->make(TenantContextInterface::class);
        $before    = $context->isResolved() ? $context->get()->getId() : null;
        $duringIds = [];
        $job       = $this->stamped();

        $result = $this->through($job, function (SolutionJob $given) use ($context, &$duringIds): string {
            $duringIds[] = $context->isResolved() ? $context->get()->getId() : null;

            return 'performed ' . $given::class;
        });

        $this->assertSame('performed ' . ForeignSolutionJob::class, $result);
        $this->assertSame([$this->tenant->getId()], $duringIds);
        $this->assertSame($before, $context->isResolved() ? $context->get()->getId() : null, 'The tenant that was current before is current again.');
        Bus::assertNothingDispatched();
    }

    public function test_the_context_is_restored_when_the_job_throws(): void
    {
        $this->activateNotes();
        $context = $this->app->make(TenantContextInterface::class);
        $before  = $context->isResolved() ? $context->get()->getId() : null;

        try {
            $this->through($this->stamped(), static function (): never {
                throw new RuntimeException('boom');
            });
            $this->fail('The failure should reach the worker.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertSame($before, $context->isResolved() ? $context->get()->getId() : null);
    }

    private function stamped(): ForeignSolutionJob
    {
        $job             = new ForeignSolutionJob('notes');
        $job->tenantId   = $this->tenant->getId();
        $job->connection = 'redis-long';
        $job->queue      = 'solution-jobs.notes';

        return $job;
    }

    /**
     * @param  (callable(SolutionJob): mixed)|null  $next  what the pipeline runs once the gate lets the job through
     */
    private function through(SolutionJob $job, ?callable $next = null): mixed
    {
        return $this->app->make(RunsSolutionJob::class)->handle($job, function (SolutionJob $given) use ($next): mixed {
            $this->ran++;

            return null === $next ? null : $next($given);
        });
    }

    private function activateNotes(): void
    {
        $this->inTenant(fn () => $this->app->make(SolutionActivator::class)->activate('notes', ActivationActor::console()));
        SolutionRequestState::forget($this->app);
    }

    /**
     * Runs the Solution for the tenant with nothing stored and nothing prepared, as a plan's grant does.
     */
    private function grantNotes(): void
    {
        $operator                                  = new FakeSolutionEntitlement();
        $operator->answers[$this->tenant->getId()] = SolutionEntitlement::grants([new SolutionGrant('notes', SolutionGrantSource::Plan)]);
        $this->app->instance(SolutionEntitlementInterface::class, $operator);
        SolutionRequestState::forget($this->app);
    }

    private function useAccessMode(FakeTenantAccessMode $mode): void
    {
        $this->app->instance(TenantAccessModeInterface::class, $mode);
        $this->app->forgetInstance(TenantAccessStates::class);
    }

    private function inTenant(callable $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant($this->tenant, $callback(...));
    }

    private function assertDropped(string $reason): void
    {
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $message, array $context): bool => 'solution_job.dropped' === $message
                && $reason === $context['reason']
                && ForeignSolutionJob::class === $context['job']
                && 'notes' === $context['solution_id'],
        )->once();
    }

    private function assertLogged(string $message, string $reason): void
    {
        Log::shouldHaveReceived('info')->withArgs(
            fn (string $given, array $context): bool => $message === $given && $reason === $context['reason'],
        )->once();
    }
}
