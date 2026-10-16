<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Enums\ActivationChange;
use App\Domains\Solution\Exceptions\SolutionEntitlementUnavailableException;
use App\Domains\Solution\Exceptions\SolutionNotEntitledException;
use App\Domains\Solution\Providers\SolutionServiceProvider;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\AllSolutionsEntitled;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Solution\Contracts\SolutionEntitlementInterface;
use Fapost\Foundation\Solution\DTO\SolutionEntitlement;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeSolutionEntitlement;
use Tests\Support\InMemorySolutionActivationStore;
use Tests\Support\InstallsFakeSolutions;
use Tests\TestCase;

/**
 * The operator's entitlement as a third factor of the effective state: computed, never written, asked at most once
 * per tenant and scope, failing open for what runs and closed for turning a Solution on.
 */
final class SolutionEntitlementTest extends TestCase
{
    use InstallsFakeSolutions;

    private InMemorySolutionActivationStore $store;

    private FakeSolutionEntitlement $operator;

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

        $this->installSolutions('feedback', 'survey');
    }

    protected function tearDown(): void
    {
        $this->removeFakeSolutions();

        parent::tearDown();
    }

    /**
     * @return array<string, array{ActivationActor}>
     */
    public static function actors(): array
    {
        return [
            'user'     => [ActivationActor::user('user-1', 'Ann')],
            'console'  => [ActivationActor::console()],
            'operator' => [ActivationActor::operator('Ops')],
        ];
    }

    public function test_without_an_operator_every_installed_solution_is_entitled(): void
    {
        $this->app->forgetInstance(SolutionEntitlementInterface::class);
        $this->app->offsetUnset(SolutionEntitlementInterface::class);
        (new SolutionServiceProvider($this->app))->register();
        $this->app->instance(SolutionActivationStoreInterface::class, $this->store);
        $this->app->forgetScopedInstances();

        $this->assertInstanceOf(AllSolutionsEntitled::class, $this->app->make(SolutionEntitlementInterface::class));

        $this->inTenant($this->alpha, function (): void {
            $this->assertSame(ActivationChange::Activated, $this->app->make(SolutionActivator::class)->activate('feedback', ActivationActor::console()));
            $this->assertSame(['feedback'], $this->app->make(ActiveSolutions::class)->ids());
        });
    }

    public function test_an_operator_binding_made_before_the_provider_wins(): void
    {
        (new SolutionServiceProvider($this->app))->register();

        $this->assertSame($this->operator, $this->app->make(SolutionEntitlementInterface::class));
    }

    public function test_a_solution_that_is_not_entitled_does_not_run_and_nothing_is_written(): void
    {
        $this->store->active['tenant-alpha']     = ['feedback', 'survey'];
        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::only(['survey', 'elsewhere']);

        $this->inTenant($this->alpha, function (): void {
            $active = $this->app->make(ActiveSolutions::class);

            $this->assertSame(['survey'], $active->ids());
            $this->assertFalse($active->isActive('feedback'));
            $this->assertFalse($active->isEntitled('feedback'));
            $this->assertTrue($active->isEntitled('survey'));
        });

        $this->assertSame(['feedback', 'survey'], $this->store->active['tenant-alpha'], 'The row keeps the tenant\'s intent.');
        $this->assertSame(0, $this->store->writes, 'Losing the entitlement journals nothing.');
    }

    public function test_the_entitlement_coming_back_runs_it_again_with_no_write(): void
    {
        $this->store->active['tenant-alpha']     = ['feedback'];
        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::only([]);

        $this->inTenant($this->alpha, fn () => $this->assertSame([], $this->app->make(ActiveSolutions::class)->ids()));

        unset($this->operator->answers['tenant-alpha']);
        $this->app->forgetScopedInstances();

        $this->inTenant($this->alpha, fn () => $this->assertSame(['feedback'], $this->app->make(ActiveSolutions::class)->ids()));
        $this->assertSame(0, $this->store->writes);
    }

    public function test_the_operator_is_not_asked_when_the_tenant_runs_nothing(): void
    {
        $this->store->active['tenant-alpha'] = ['gone'];

        $this->inTenant($this->alpha, fn () => $this->assertSame([], $this->app->make(ActiveSolutions::class)->ids()));

        $this->assertSame([], $this->operator->asked);
    }

    public function test_it_asks_the_operator_once_per_tenant_and_scope_and_never_mixes_tenants(): void
    {
        $this->store->active['tenant-alpha']    = ['feedback'];
        $this->store->active['tenant-beta']     = ['feedback'];
        $this->operator->answers['tenant-beta'] = SolutionEntitlement::only([]);

        $this->inTenant($this->alpha, function (): void {
            $active = $this->app->make(ActiveSolutions::class);
            $this->assertTrue($active->isActive('feedback'));

            $this->inTenant($this->beta, function () use ($active): void {
                $this->assertFalse($active->isActive('feedback'), 'Alpha\'s answer is not given to beta.');
                $this->assertFalse($active->isEntitled('feedback'));
            });

            $this->assertTrue($active->isActive('feedback'));
            $this->assertTrue($active->isEntitled('feedback'));
        });

        $this->assertSame(['tenant-alpha', 'tenant-beta'], $this->operator->asked);

        // The next job of the same worker asks again.
        $this->app->forgetScopedInstances();
        $this->inTenant($this->alpha, fn () => $this->app->make(ActiveSolutions::class)->ids());

        $this->assertSame(['tenant-alpha', 'tenant-beta', 'tenant-alpha'], $this->operator->asked);
    }

    public function test_an_operator_outage_keeps_what_runs_and_is_reported_once_per_scope(): void
    {
        Exceptions::fake();
        $this->store->active['tenant-alpha']     = ['feedback'];
        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::only([]);
        $this->operator->failing                 = true;

        $this->inTenant($this->alpha, function (): void {
            $active = $this->app->make(ActiveSolutions::class);

            $this->assertSame(['feedback'], $active->ids(), 'Fail open: what the tenant turned on keeps running.');
            $this->assertFalse($active->isActive('survey'), 'Nothing the tenant did not turn on starts.');
            $this->assertNull($active->isEntitled('feedback'));
        });

        $this->assertCount(1, $this->operator->asked);
        Exceptions::assertReportedCount(1);
    }

    #[DataProvider('actors')]
    public function test_turning_on_a_solution_that_is_not_entitled_is_refused_for_every_actor(ActivationActor $actor): void
    {
        $this->operator->answers['tenant-alpha'] = SolutionEntitlement::only(['survey']);

        $this->inTenant($this->alpha, function () use ($actor): void {
            try {
                $this->app->make(SolutionActivator::class)->activate('feedback', $actor);
                $this->fail('A Solution that is not entitled was turned on.');
            } catch (SolutionNotEntitledException) {
            }

            $this->assertSame(ActivationChange::Activated, $this->app->make(SolutionActivator::class)->activate('survey', $actor));
        });

        $this->assertSame(['survey'], $this->store->active['tenant-alpha']);
        $this->assertSame(1, $this->store->writes, 'The refusal journals nothing.');
    }

    public function test_turning_one_on_while_the_operator_is_down_is_refused(): void
    {
        Exceptions::fake();
        $this->operator->failing = true;

        $this->inTenant($this->alpha, function (): void {
            $this->expectException(SolutionEntitlementUnavailableException::class);

            $this->app->make(SolutionActivator::class)->activate('feedback', ActivationActor::console());
        });
    }

    public function test_turning_one_off_needs_no_entitlement(): void
    {
        $this->store->active['tenant-alpha'] = ['feedback'];
        $this->operator->failing             = true;

        $this->inTenant($this->alpha, function (): void {
            $this->assertSame(ActivationChange::Deactivated, $this->app->make(SolutionActivator::class)->deactivate('feedback', ActivationActor::console()));
        });

        $this->assertSame([], $this->operator->asked);
    }

    private function inTenant(RuntimeTenant $tenant, callable $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant($tenant, $callback(...));
    }
}
