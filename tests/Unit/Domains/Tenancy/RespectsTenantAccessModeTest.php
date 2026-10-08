<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\StoppedTenantAction;
use App\Domains\Tenancy\Services\AlwaysActiveAccessMode;
use App\Jobs\Flow\ResumeDelayedFlowSessionJob;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Fapost\Foundation\Tenancy\DTO\TenantAccessState;
use LogicException;
use RuntimeException;
use stdClass;
use Tests\Support\FakeTenantAccessMode;
use Tests\TestCase;

final class RespectsTenantAccessModeTest extends TestCase
{
    public function test_core_answers_active_by_default(): void
    {
        $this->assertInstanceOf(AlwaysActiveAccessMode::class, $this->app->make(TenantAccessModeInterface::class));
        $this->assertFalse($this->app->make(TenantAccessModeInterface::class)->stateFor('t-1')->isStopped());
    }

    public function test_an_active_tenant_runs_the_job(): void
    {
        Bus::fake();
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::active());

        $ran = $this->pass($this->job(), StoppedTenantAction::Drop);

        $this->assertTrue($ran);
        Bus::assertNothingDispatched();
    }

    public function test_a_stopped_tenant_drops_the_job_and_logs_it(): void
    {
        Bus::fake();
        Log::spy();
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped());

        $ran = $this->pass($this->job(), StoppedTenantAction::Drop);

        $this->assertFalse($ran);
        Bus::assertNothingDispatched();
        Log::shouldHaveReceived('info')->with('tenant.access_mode.job_dropped', [
            'job'       => ResumeDelayedFlowSessionJob::class,
            'tenant_id' => 'tenant-1',
        ])->once();
    }

    public function test_a_stopped_tenant_postpones_the_job_as_a_delayed_copy_on_its_own_queue(): void
    {
        config(['queue.default' => 'redis']);
        Bus::fake();
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped());
        $job      = $this->job();
        $job->job = new stdClass(); // the queue job a worker would have attached must not travel

        $ran = $this->pass($job, StoppedTenantAction::Postpone);

        $this->assertFalse($ran);
        Bus::assertDispatchedTimes(ResumeDelayedFlowSessionJob::class, 1);
        Bus::assertDispatched(ResumeDelayedFlowSessionJob::class, static fn (ResumeDelayedFlowSessionJob $copy): bool => $copy !== $job
            && 3600 === $copy->delay
            && 'flow.execution' === $copy->queue
            && 'session-1' === $copy->sessionId
            && null === $copy->job);
    }

    public function test_a_postponed_job_is_not_queued_again_on_a_sync_queue(): void
    {
        config(['queue.default' => 'sync']);
        Bus::fake();
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped());

        $ran = $this->pass($this->job(), StoppedTenantAction::Postpone);

        $this->assertFalse($ran);
        Bus::assertNothingDispatched();
    }

    public function test_the_jobs_own_connection_decides_whether_it_is_sync(): void
    {
        config(['queue.default' => 'sync']);
        Bus::fake();
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped());
        $job             = $this->job();
        $job->connection = 'redis';

        $this->assertFalse($this->pass($job, StoppedTenantAction::Postpone));

        Bus::assertDispatchedTimes(ResumeDelayedFlowSessionJob::class, 1);
    }

    public function test_an_operator_that_cannot_be_asked_counts_as_active(): void
    {
        $this->app->instance(TenantAccessModeInterface::class, new class implements TenantAccessModeInterface {
            public function stateFor(string $tenantId): TenantAccessState
            {
                throw new RuntimeException('operator is down');
            }
        });

        $this->assertTrue($this->pass($this->job(), StoppedTenantAction::Drop));
    }

    public function test_a_job_that_does_not_name_its_tenant_is_a_programming_error(): void
    {
        $this->expectException(LogicException::class);

        new RespectsTenantAccessMode(StoppedTenantAction::Drop)->handle(new stdClass(), static fn (): null => null);
    }

    /**
     * Sends the job through the middleware and reports whether it got as far as running.
     */
    private function pass(ResumeDelayedFlowSessionJob $job, StoppedTenantAction $action): bool
    {
        $ran = false;

        new Pipeline($this->app)
            ->send($job)
            ->through([new RespectsTenantAccessMode($action)])
            ->then(static function () use (&$ran): void {
                $ran = true;
            });

        return $ran;
    }

    private function job(): ResumeDelayedFlowSessionJob
    {
        return new ResumeDelayedFlowSessionJob('tenant-1', 'session-1', 'node-1', '2026-10-08T10:00:00+00:00');
    }
}
