<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Tenancy\Contracts\TenantDeletionRequestRepositoryInterface;
use App\Domains\Tenancy\Jobs\DecommissionTenantJob;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantDeletionRequest;
use App\Domains\Tenancy\ValueObjects\TenantAdmin;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Tenancy\Enums\DecommissionReason;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\FeatureTestCase;

/**
 * The scheduler's minute: only a request that is due, not cancelled and not started is carried out; the record keeps
 * at most one active request per tenant.
 */
final class TenantsStartDeletionsCommandTest extends FeatureTestCase
{
    public function test_only_due_uncancelled_unstarted_requests_are_queued(): void
    {
        Queue::fake();
        $now = CarbonImmutable::now('UTC');

        $due       = $this->schedule('due', $now->subHour());
        $notYet    = $this->schedule('not-yet', $now->addHour());
        $cancelled = $this->schedule('cancelled', $now->subHour());
        $started   = $this->schedule('started', $now->subHour());
        $repo      = $this->app->make(TenantDeletionRequestRepositoryInterface::class);
        $repo->cancel($cancelled->getKey(), $this->admin(), $now);
        $repo->claim($started->getKey(), $now);

        $this->artisan('tenants:start-deletions')->expectsOutputToContain('Queued 1')->assertExitCode(0);

        Queue::assertPushed(DecommissionTenantJob::class, 1);
        Queue::assertPushed(
            DecommissionTenantJob::class,
            static fn (DecommissionTenantJob $job): bool => $job->tenantId === $due->tenant_id
                && $job->requestId === $due->getKey()
                && DecommissionReason::OwnerRequest === $job->reason
                && 'tenancy.decommission' === $job->queue,
        );
        $this->assertNotNull($notYet);
    }

    public function test_it_runs_every_minute_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(static fn ($event): bool => str_contains((string) $event->command, 'tenants:start-deletions'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_a_second_active_request_for_a_tenant_is_refused_and_a_cancelled_one_frees_the_place(): void
    {
        $first = $this->schedule('acme', CarbonImmutable::now('UTC')->addDay());
        $repo  = $this->app->make(TenantDeletionRequestRepositoryInterface::class);
        $now   = CarbonImmutable::now('UTC');

        $this->assertNull($repo->schedule($first->tenant_id, $this->admin(), $now, $now->addDay()));
        $this->assertTrue($repo->cancel($first->getKey(), $this->admin(), $now));
        $this->assertNotNull($repo->schedule($first->tenant_id, $this->admin(), $now, $now->addDay()));
    }

    private function schedule(string $slug, CarbonImmutable $startsAt): TenantDeletionRequest
    {
        $tenant  = Tenant::on('landlord')->create(['slug' => $slug, 'schema_name' => 'tenant_' . str_replace('-', '_', $slug), 'status' => 'active', 'config' => []]);
        $request = $this->app->make(TenantDeletionRequestRepositoryInterface::class)
            ->schedule($tenant->getKey(), $this->admin(), $startsAt->subDay(), $startsAt);

        $this->assertNotNull($request);

        return $request;
    }

    private function admin(): TenantAdmin
    {
        return new TenantAdmin('00000000-0000-0000-0000-0000000000aa', 'Ann', 'ann@example.test');
    }
}
