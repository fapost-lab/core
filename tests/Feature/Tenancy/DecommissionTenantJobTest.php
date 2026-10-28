<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Tenancy\Contracts\TenantDeletionRequestRepositoryInterface;
use App\Domains\Tenancy\Jobs\DecommissionTenantJob;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantDeletionRequest;
use App\Domains\Tenancy\ValueObjects\TenantAdmin;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Tenancy\Contracts\TenantDecommissionerInterface;
use Fapost\Foundation\Tenancy\DTO\DecommissionedTenant;
use Fapost\Foundation\Tenancy\Enums\DecommissionReason;
use Fapost\Foundation\Tenancy\Exceptions\TenantDecommissionFailedException;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * The queued removal: it hands the tenant and the reason to the contract, retries a platform failure, gives up at once
 * on a tenant that is still being provisioned, and for an owner's request re-checks that it was not cancelled.
 */
final class DecommissionTenantJobTest extends FeatureTestCase
{
    private const string ID = '01890a5d-ac96-774b-bcce-b302099a8057';

    public function test_it_calls_the_contract_with_the_tenant_and_the_reason(): void
    {
        $decommissioner = $this->decommissioner();
        $decommissioner->shouldReceive('decommission')->once()->with(self::ID, DecommissionReason::Retention)
            ->andReturn(new DecommissionedTenant(self::ID, 'acme', false, false));

        $this->handle(new DecommissionTenantJob(self::ID, DecommissionReason::Retention), $decommissioner);
    }

    public function test_it_runs_alone_per_tenant_on_a_queue_whose_connection_outlasts_its_timeout(): void
    {
        $job = new DecommissionTenantJob(self::ID, DecommissionReason::OwnerRequest);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(self::ID, $job->uniqueId());
        $this->assertSame('tenancy.decommission', $job->queue);
        $this->assertSame('redis-long', $job->connection);
        $this->assertGreaterThan($job->timeout, (int) config('queue.connections.redis-long.retry_after'));
    }

    public function test_a_platform_failure_is_rethrown_so_the_queue_retries(): void
    {
        $decommissioner = $this->decommissioner();
        $decommissioner->shouldReceive('decommission')->andThrow(TenantDecommissionFailedException::failed(self::ID, new RuntimeException('x')));

        $this->expectException(TenantDecommissionFailedException::class);

        $this->handle(new DecommissionTenantJob(self::ID, DecommissionReason::OwnerRequest), $decommissioner);
    }

    public function test_a_pending_tenant_fails_the_job_without_a_retry(): void
    {
        $decommissioner = $this->decommissioner();
        $decommissioner->shouldReceive('decommission')->andThrow(TenantDecommissionFailedException::pending(self::ID));

        $queued = Mockery::mock(QueueJob::class);
        $queued->shouldReceive('fail')->once()->with(Mockery::type(TenantDecommissionFailedException::class));

        $job = new DecommissionTenantJob(self::ID, DecommissionReason::OwnerRequest);
        $job->setJob($queued);

        $this->handle($job, $decommissioner);
    }

    public function test_a_cancelled_request_stops_the_job_before_anything_is_removed(): void
    {
        [$request] = $this->request();
        $this->app->make(TenantDeletionRequestRepositoryInterface::class)->cancel($request->getKey(), new TenantAdmin('u2', 'Other', 'o@example.test'), CarbonImmutable::now());

        $decommissioner = $this->decommissioner();
        $decommissioner->shouldNotReceive('decommission');

        $this->handle(new DecommissionTenantJob($request->tenant_id, DecommissionReason::OwnerRequest, $request->getKey()), $decommissioner);

        $this->assertNull($request->fresh()->started_at);
    }

    public function test_an_active_request_is_marked_started_and_the_removal_runs_and_can_no_longer_be_cancelled(): void
    {
        [$request]      = $this->request();
        $decommissioner = $this->decommissioner();
        $decommissioner->shouldReceive('decommission')->once()->with($request->tenant_id, DecommissionReason::OwnerRequest)
            ->andReturn(new DecommissionedTenant($request->tenant_id, 'acme', false, false));

        $this->handle(new DecommissionTenantJob($request->tenant_id, DecommissionReason::OwnerRequest, $request->getKey()), $decommissioner);

        $this->assertNotNull($request->fresh()->started_at);
        $this->assertFalse($this->app->make(TenantDeletionRequestRepositoryInterface::class)->cancel($request->getKey(), new TenantAdmin('u2', 'Other', 'o@example.test'), CarbonImmutable::now()));
    }

    public function test_a_retry_of_a_started_request_goes_on(): void
    {
        [$request] = $this->request();
        $this->app->make(TenantDeletionRequestRepositoryInterface::class)->claim($request->getKey(), CarbonImmutable::now());

        $decommissioner = $this->decommissioner();
        $decommissioner->shouldReceive('decommission')->once()->andReturn(new DecommissionedTenant($request->tenant_id, 'acme', false, false));

        $this->handle(new DecommissionTenantJob($request->tenant_id, DecommissionReason::OwnerRequest, $request->getKey()), $decommissioner);
    }

    public function test_a_request_that_is_gone_stops_the_job(): void
    {
        $decommissioner = $this->decommissioner();
        $decommissioner->shouldNotReceive('decommission');

        $this->handle(new DecommissionTenantJob(self::ID, DecommissionReason::OwnerRequest, '01890a5d-ac96-774b-bcce-b302099a8099'), $decommissioner);
    }

    private function handle(DecommissionTenantJob $job, TenantDecommissionerInterface $decommissioner): void
    {
        $job->handle($decommissioner, $this->app->make(TenantDeletionRequestRepositoryInterface::class));
    }

    /**
     * @return array{0: TenantDeletionRequest}
     */
    private function request(): array
    {
        $tenant  = Tenant::on('landlord')->create(['slug' => 'acme', 'schema_name' => 'tenant_acme', 'status' => 'active', 'config' => []]);
        $now     = CarbonImmutable::now('UTC');
        $request = $this->app->make(TenantDeletionRequestRepositoryInterface::class)
            ->schedule($tenant->getKey(), new TenantAdmin('00000000-0000-0000-0000-0000000000aa', 'Ann', 'ann@example.test'), $now->subDay(), $now);

        $this->assertNotNull($request);

        return [$request];
    }

    private function decommissioner(): MockInterface&TenantDecommissionerInterface
    {
        return Mockery::mock(TenantDecommissionerInterface::class);
    }
}
