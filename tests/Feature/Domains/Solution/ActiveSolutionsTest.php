<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Exceptions\TenantNotResolvedException;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Tests\Support\InMemorySolutionActivationStore;
use Tests\Support\InstallsFakeSolutions;
use Tests\TestCase;

/**
 * The registry's behaviour across tenants and jobs. The store is in memory, keyed by tenant id: SQLite has
 * no schemas to switch, and these tests are about what the registry remembers, not where rows live
 * (that is {@see \Tests\Feature\Tenancy\PostgresSolutionActivationTest}).
 */
final class ActiveSolutionsTest extends TestCase
{
    use InstallsFakeSolutions;

    private InMemorySolutionActivationStore $store;

    private RuntimeTenant $alpha;

    private RuntimeTenant $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = new RuntimeTenant('tenant-alpha', 'alpha', 'alpha');
        $this->beta  = new RuntimeTenant('tenant-beta', 'beta', 'beta');

        $this->store = new InMemorySolutionActivationStore(fn (): TenantContextInterface => $this->app->make(TenantContextInterface::class));
        $this->app->instance(SolutionActivationStoreInterface::class, $this->store);
        $this->installSolutions('feedback', 'survey');
    }

    protected function tearDown(): void
    {
        $this->removeFakeSolutions();

        parent::tearDown();
    }

    public function test_nothing_is_active_by_default(): void
    {
        $this->inTenant($this->alpha, function (): void {
            $active = $this->app->make(ActiveSolutions::class);

            $this->assertSame([], $active->ids());
            $this->assertFalse($active->isActive('feedback'));
        });
    }

    public function test_it_needs_a_tenant(): void
    {
        $this->expectException(TenantNotResolvedException::class);

        $this->app->make(ActiveSolutions::class)->ids();
    }

    public function test_a_stored_row_counts_only_while_the_solution_is_installed(): void
    {
        $this->store->active['tenant-alpha'] = ['feedback', 'gone'];

        $this->inTenant($this->alpha, function (): void {
            $active = $this->app->make(ActiveSolutions::class);

            $this->assertSame(['feedback'], $active->ids(), 'An orphan row is not active.');
            $this->assertFalse($active->isActive('gone'));
            $this->assertFalse($active->isActive('survey'), 'Installed but never activated.');
        });
    }

    public function test_the_package_coming_back_activates_it_again_where_the_tenant_left_it_on(): void
    {
        $this->store->active['tenant-alpha'] = ['feedback'];
        $this->installSolutions('survey');

        $this->inTenant($this->alpha, fn () => $this->assertSame([], $this->app->make(ActiveSolutions::class)->ids()));

        $this->installSolutions('feedback', 'survey');
        $this->app->forgetScopedInstances();

        $this->inTenant($this->alpha, fn () => $this->assertSame(['feedback'], $this->app->make(ActiveSolutions::class)->ids()));
    }

    public function test_it_asks_the_store_once_per_tenant_and_scope(): void
    {
        $this->store->active['tenant-alpha'] = ['feedback'];

        $this->inTenant($this->alpha, function (): void {
            $active = $this->app->make(ActiveSolutions::class);
            $this->assertSame(0, $this->store->reads, 'Lazy: nothing is read before the first question.');

            $active->isActive('feedback');
            $active->ids();
            $active->isActive('survey');

            $this->assertSame(1, $this->store->reads);
        });
    }

    public function test_two_tenants_in_one_job_get_their_own_answers_even_when_nested(): void
    {
        $this->store->active['tenant-alpha'] = ['feedback'];
        $this->store->active['tenant-beta']  = ['survey'];

        $this->inTenant($this->alpha, function (): void {
            $active = $this->app->make(ActiveSolutions::class);
            $this->assertSame(['feedback'], $active->ids());

            $this->inTenant($this->beta, function () use ($active): void {
                $this->assertSame(['survey'], $active->ids(), 'The memo of alpha is not given to beta.');
                $this->assertFalse($active->isActive('feedback'));

                $this->inTenant($this->alpha, fn () => $this->assertSame(['feedback'], $active->ids()));
            });

            $this->assertSame(['feedback'], $active->ids(), 'Back in alpha after the nested switches.');
        });

        $this->assertSame(2, $this->store->reads, 'One read per tenant, however many switches.');
    }

    public function test_two_jobs_of_one_worker_do_not_share_the_memo(): void
    {
        $this->store->active['tenant-alpha'] = ['feedback'];

        $this->inTenant($this->alpha, fn () => $this->assertTrue($this->app->make(ActiveSolutions::class)->isActive('feedback')));

        // The worker drops scoped instances before the next job (QueueServiceProvider); that job is another tenant's.
        $this->app->forgetScopedInstances();

        $this->inTenant($this->beta, fn () => $this->assertFalse($this->app->make(ActiveSolutions::class)->isActive('feedback')));
    }

    public function test_a_deactivation_is_seen_by_the_next_job_of_the_same_process(): void
    {
        $this->store->active['tenant-alpha'] = ['feedback'];

        $this->inTenant($this->alpha, fn () => $this->assertTrue($this->app->make(ActiveSolutions::class)->isActive('feedback')));

        // Another process deactivates between the two jobs: this one's memo is gone with its scope.
        $this->store->active['tenant-alpha'] = [];
        $this->app->forgetScopedInstances();

        $this->inTenant($this->alpha, fn () => $this->assertFalse($this->app->make(ActiveSolutions::class)->isActive('feedback')));
    }

    public function test_the_activator_drops_only_its_own_tenants_memo(): void
    {
        $this->store->active['tenant-beta'] = ['survey'];

        $this->inTenant($this->alpha, function (): void {
            $active = $this->app->make(ActiveSolutions::class);

            $this->inTenant($this->beta, fn () => $this->assertSame(['survey'], $active->ids()));
            $this->assertFalse($active->isActive('feedback'));

            $this->app->make(SolutionActivator::class)->activate('feedback', ActivationActor::console());

            $this->assertTrue($active->isActive('feedback'), 'The write is visible at once, in the same job.');

            $reads = $this->store->reads;
            $this->inTenant($this->beta, fn () => $this->assertSame(['survey'], $active->ids()));
            $this->assertSame($reads, $this->store->reads, 'Beta stayed memoised.');
        });
    }

    private function inTenant(RuntimeTenant $tenant, callable $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant($tenant, $callback(...));
    }
}
