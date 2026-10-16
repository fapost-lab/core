<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\Contracts\SolutionGrantSnapshotStoreInterface;
use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Enums\ActivationChange;
use App\Domains\Solution\Exceptions\SolutionEntitlementUnavailableException;
use App\Domains\Solution\Exceptions\SolutionGrantedException;
use App\Domains\Solution\Infrastructure\CacheSolutionGrantSnapshotStore;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\AllSolutionsEntitled;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Solution\Services\SolutionEntitlements;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use DateTimeImmutable;
use Fapost\Foundation\Solution\Contracts\SolutionEntitlementInterface;
use Fapost\Foundation\Solution\DTO\SolutionEntitlement;
use Fapost\Foundation\Solution\DTO\SolutionGrant;
use Fapost\Foundation\Solution\Enums\SolutionGrantSource;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\Support\FakeSolutionEntitlement;
use Tests\Support\InMemorySolutionActivationStore;
use Tests\Support\InstallsFakeSolutions;
use Tests\TestCase;

/**
 * A Solution the operator grants (its plan's bundle, a running add-on) runs with nothing stored and cannot be turned off
 * by the tenant; the grants of the last answer keep it running for a bounded time while the operator cannot answer.
 */
final class SolutionGrantsTest extends TestCase
{
    use InstallsFakeSolutions;

    private InMemorySolutionActivationStore $store;

    private FakeSolutionEntitlement $operator;

    private Repository $cache;

    private RuntimeTenant $alpha;

    private RuntimeTenant $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = new RuntimeTenant('tenant-alpha', 'alpha', 'alpha');
        $this->beta  = new RuntimeTenant('tenant-beta', 'beta', 'beta');

        $this->store = new InMemorySolutionActivationStore(fn (): TenantContextInterface => $this->app->make(TenantContextInterface::class));
        $this->app->instance(SolutionActivationStoreInterface::class, $this->store);

        $this->operator = new FakeSolutionEntitlement();
        $this->app->instance(SolutionEntitlementInterface::class, $this->operator);

        $this->cache = new Repository(new ArrayStore());
        $this->app->instance(SolutionGrantSnapshotStoreInterface::class, new CacheSolutionGrantSnapshotStore($this->cache));

        $this->installSolutions('feedback', 'survey', 'crm');
    }

    protected function tearDown(): void
    {
        $this->removeFakeSolutions();

        parent::tearDown();
    }

    public function test_a_granted_solution_runs_with_nothing_stored_beside_what_the_tenant_turned_on(): void
    {
        $this->store->active['tenant-alpha']     = ['survey', 'crm'];
        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::grants([$this->grant('feedback'), $this->grant('elsewhere')], ['survey']);

        $this->inTenant($this->alpha, function (): void {
            $active = $this->app->make(ActiveSolutions::class);

            $this->assertSame(['feedback', 'survey'], $active->ids(), 'Granted, plus stored and allowed; crm is neither.');
            $this->assertSame(SolutionGrantSource::Plan, $active->grantFor('feedback')?->source);
            $this->assertNull($active->grantFor('survey'));
        });

        $this->assertSame(0, $this->store->writes);
    }

    public function test_a_grant_that_ends_stops_the_solution_with_nothing_written_and_its_return_runs_it_again(): void
    {
        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::grants([$this->grant('feedback')]);
        $this->inTenant($this->alpha, fn () => $this->assertSame(['feedback'], $this->app->make(ActiveSolutions::class)->ids()));

        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::grants([]);
        $this->app->forgetScopedInstances();
        $this->inTenant($this->alpha, fn () => $this->assertSame([], $this->app->make(ActiveSolutions::class)->ids()));

        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::grants([$this->grant('feedback', SolutionGrantSource::Addon)]);
        $this->app->forgetScopedInstances();
        $this->inTenant($this->alpha, fn () => $this->assertSame(['feedback'], $this->app->make(ActiveSolutions::class)->ids()));

        $this->assertSame(0, $this->store->writes);
    }

    public function test_turning_a_granted_solution_on_writes_nothing_and_turning_it_off_is_refused(): void
    {
        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::grants([$this->grant('feedback')]);

        $this->inTenant($this->alpha, function (): void {
            $activator = $this->app->make(SolutionActivator::class);

            $this->assertSame(ActivationChange::Unchanged, $activator->activate('feedback', ActivationActor::console()));

            $this->expectException(SolutionGrantedException::class);
            $activator->deactivate('feedback', ActivationActor::user('user-1', 'Ann'));
        });
    }

    public function test_a_granted_solution_the_tenant_had_turned_off_runs_anyway(): void
    {
        $this->store->active['tenant-alpha']     = [];
        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::grants([$this->grant('feedback')]);

        $this->inTenant($this->alpha, fn () => $this->assertTrue($this->app->make(ActiveSolutions::class)->isActive('feedback')));
    }

    public function test_an_outage_runs_the_grants_of_the_last_answer_and_what_the_tenant_turned_on(): void
    {
        Exceptions::fake();
        $this->store->active['tenant-alpha']     = ['survey'];
        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::grants([$this->grant('feedback'), $this->grant('elsewhere')], ['survey']);
        $this->inTenant($this->alpha, fn () => $this->app->make(ActiveSolutions::class)->ids());

        $this->operator->failing = true;
        $this->app->forgetScopedInstances();

        $this->inTenant($this->alpha, function (): void {
            $active = $this->app->make(ActiveSolutions::class);

            $this->assertSame(['feedback', 'survey'], $active->ids());
            $this->assertFalse($active->isActive('crm'), 'Nothing new starts.');
        });

        // Turning one on, and so connecting, still waits for the operator.
        $this->inTenant($this->alpha, function (): void {
            $this->expectException(SolutionEntitlementUnavailableException::class);
            $this->app->make(SolutionActivator::class)->activate('crm', ActivationActor::console());
        });
    }

    public function test_a_solution_running_from_the_last_grants_cannot_be_turned_off_during_an_outage(): void
    {
        Exceptions::fake();
        $this->store->active['tenant-alpha']     = ['survey'];
        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::grants([$this->grant('feedback')], ['survey']);
        $this->inTenant($this->alpha, fn () => $this->app->make(ActiveSolutions::class)->ids());

        $this->operator->failing = true;
        $this->app->forgetScopedInstances();

        $this->inTenant($this->alpha, function (): void {
            $activator = $this->app->make(SolutionActivator::class);

            $this->assertSame(ActivationChange::Deactivated, $activator->deactivate('survey', ActivationActor::console()), 'What the tenant turned on can still be turned off.');

            $this->expectException(SolutionGrantedException::class);
            $activator->deactivate('feedback', ActivationActor::console());
        });
    }

    public function test_the_last_grants_are_kept_for_a_day_at_most(): void
    {
        Exceptions::fake();
        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::grants([$this->grant('feedback')]);
        $this->inTenant($this->alpha, fn () => $this->app->make(ActiveSolutions::class)->ids());

        $this->operator->failing = true;
        $this->travel(SolutionEntitlements::LAST_ANSWER_TTL_SECONDS + 1)->seconds();
        $this->app->forgetScopedInstances();

        $this->inTenant($this->alpha, fn () => $this->assertSame([], $this->app->make(ActiveSolutions::class)->ids()));
    }

    public function test_an_answer_without_grants_clears_the_kept_ones(): void
    {
        Exceptions::fake();
        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::grants([$this->grant('feedback')]);
        $this->inTenant($this->alpha, fn () => $this->app->make(ActiveSolutions::class)->ids());

        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::only(['feedback']);
        $this->app->forgetScopedInstances();
        $this->inTenant($this->alpha, fn () => $this->app->make(ActiveSolutions::class)->ids());

        $this->operator->failing = true;
        $this->app->forgetScopedInstances();

        $this->inTenant($this->alpha, fn () => $this->assertSame([], $this->app->make(ActiveSolutions::class)->ids()));
    }

    public function test_each_tenant_keeps_its_own_grants(): void
    {
        Exceptions::fake();
        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::grants([$this->grant('feedback')]);
        $this->operator->answers['tenant-beta']  = SolutionEntitlement::grants([$this->grant('crm', SolutionGrantSource::Addon, new DateTimeImmutable('2026-11-01T00:00:00Z'))]);
        $this->inTenant($this->alpha, fn () => $this->app->make(ActiveSolutions::class)->ids());
        $this->inTenant($this->beta, fn () => $this->app->make(ActiveSolutions::class)->ids());

        $this->operator->failing = true;
        $this->app->forgetScopedInstances();

        $this->inTenant($this->alpha, function (): void {
            $active = $this->app->make(ActiveSolutions::class);
            $this->assertSame(['feedback'], $active->ids());
            $this->inTenant($this->beta, fn () => $this->assertSame(['crm'], $active->ids()));
        });

        $kept = $this->app->make(SolutionGrantSnapshotStoreInterface::class)->get('tenant-beta');
        $this->assertSame(SolutionGrantSource::Addon, $kept[0]->source);
        $this->assertSame('2026-11-01T00:00:00+00:00', $kept[0]->endsAt?->format(DATE_ATOM));
    }

    public function test_a_failing_cache_does_not_fail_the_question(): void
    {
        Exceptions::fake();
        $this->app->instance(SolutionGrantSnapshotStoreInterface::class, new class () implements SolutionGrantSnapshotStoreInterface {
            public function put(string $tenantId, array $grants, int $ttlSeconds): void
            {
                throw new RuntimeException('Redis is down.');
            }

            public function get(string $tenantId): array
            {
                throw new RuntimeException('Redis is down.');
            }

            public function forget(string $tenantId): void
            {
                throw new RuntimeException('Redis is down.');
            }
        });
        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::grants([$this->grant('feedback')]);

        $this->inTenant($this->alpha, fn () => $this->assertSame(['feedback'], $this->app->make(ActiveSolutions::class)->ids()));

        $this->operator->failing = true;
        $this->app->forgetScopedInstances();
        $this->inTenant($this->alpha, fn () => $this->assertSame([], $this->app->make(ActiveSolutions::class)->ids()));
    }

    public function test_with_core_s_default_nothing_is_kept(): void
    {
        $this->app->instance(SolutionEntitlementInterface::class, new AllSolutionsEntitled());
        $this->cache->put('fapost:solution-grants:tenant-alpha', [['id' => 'feedback', 'source' => 'plan']], 60);

        $this->inTenant($this->alpha, fn () => $this->app->make(ActiveSolutions::class)->ids());

        $this->assertNotNull($this->cache->get('fapost:solution-grants:tenant-alpha'), 'The default answer touches no cache.');
    }

    private function grant(string $id, SolutionGrantSource $source = SolutionGrantSource::Plan, ?DateTimeImmutable $endsAt = null): SolutionGrant
    {
        return new SolutionGrant($id, $source, $endsAt);
    }

    private function inTenant(RuntimeTenant $tenant, callable $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant($tenant, $callback(...));
    }
}
