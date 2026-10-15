<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Call\CallTester;
use App\Domains\Flow\Call\Transports\HandlerTransport;
use App\Domains\Flow\Handlers\CallNodeHandler;
use App\Domains\Flow\Services\LoadBuilderFlowService;
use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Providers\SolutionServiceProvider;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionManifestValidator;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Action\ActionHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\Flow\Call\CallContext;
use Fapost\Foundation\Flow\Call\CallRequest;
use Fapost\Foundation\Quota\Contracts\UsageMeterInterface;
use Fapost\Foundation\Solution\Manifest\ManifestParser;
use Tests\Fixtures\Solution\FeedbackCalls;
use Tests\Fixtures\Solution\FeedbackProbe;
use Tests\Support\FakeUsageMeter;
use Tests\Support\FakeVendor;
use Tests\Support\InMemorySolutionActivationStore;
use Tests\TestCase;

/**
 * The fixture Solution `feedback` (action `feedback.submit`) installed in a booted application, with
 * activation per tenant: the action is reached while the tenant has it on and refused as soon as it is off,
 * in the same process, for the runtime node and for the builder's test call.
 */
final class SolutionActionsRuntimeTest extends TestCase
{
    private FakeVendor $vendor;

    private InMemorySolutionActivationStore $store;

    private FeedbackCalls $calls;

    private RuntimeTenant $alpha;

    private RuntimeTenant $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = new RuntimeTenant('tenant-alpha', 'alpha', 'alpha');
        $this->beta  = new RuntimeTenant('tenant-beta', 'beta', 'beta');

        $this->calls = new FeedbackCalls();
        $this->app->instance(FeedbackCalls::class, $this->calls);
        $this->app->scoped(FeedbackProbe::class);

        $this->vendor = new FakeVendor();
        $this->vendor->package(FakeVendor::solutionComposer())->write();

        $installed = new InstalledSolutions(
            new SolutionCache(dirname($this->vendor->path) . '/fapost-solutions.php'),
            new SolutionDiscoverer(
                new InstalledPackagesReader($this->vendor->path),
                new SolutionManifestValidator(new ManifestParser()),
            ),
        );
        $this->app->instance(InstalledSolutions::class, $installed);

        // What Core does at startup: register the providers, then give each its registrar.
        $provider = new SolutionServiceProvider($this->app);
        $provider->register();
        $provider->registerExtensions($installed->all());

        // The provider binds the Eloquent store; the tests are about the registry, not where rows live.
        $this->store = new InMemorySolutionActivationStore(fn (): TenantContextInterface => $this->app->make(TenantContextInterface::class));
        $this->app->instance(SolutionActivationStoreInterface::class, $this->store);
        $this->app->forgetInstance(ActiveSolutions::class);
    }

    protected function tearDown(): void
    {
        $this->vendor->cleanup();

        parent::tearDown();
    }

    public function test_the_action_of_an_active_solution_is_reached_by_the_call_node(): void
    {
        $this->inTenant($this->alpha, function (): void {
            $this->activate();

            $result = $this->runNode();

            $this->assertSame('success', $result->sourceHandle);
            $this->assertNull($result->errorMessage);
        });

        $this->assertCount(1, $this->calls->calls);
        $this->assertSame('feedback.submit', $this->calls->calls[0]['action']);
        $this->assertSame('tenant-alpha', $this->calls->calls[0]['tenant_id'], 'The handler sees the tenant the job runs for.');
        $this->assertSame(['rating' => 5], $this->calls->calls[0]['parameters']);
    }

    public function test_the_transport_returns_the_payload_of_the_handler(): void
    {
        $this->inTenant($this->alpha, function (): void {
            $this->activate();

            $result = $this->app->make(HandlerTransport::class)->execute(
                new CallRequest(target: 'feedback.submit', parameters: ['rating' => 3]),
                $this->callContext(),
            );

            $this->assertTrue($result->success);
            $this->assertSame(['rating' => 3], $result->payload['echo']);
            $this->assertSame('tenant-alpha', $result->payload['tenant_id']);
        });
    }

    public function test_after_deactivation_the_call_is_refused_and_the_handler_is_not_called(): void
    {
        $this->inTenant($this->alpha, function (): void {
            $this->activate();
            $this->assertSame('success', $this->runNode()->sourceHandle);

            $this->app->make(SolutionActivator::class)->deactivate('feedback', ActivationActor::console());
            $built = $this->calls->constructions;

            $result = $this->runNode();

            $this->assertSame('error', $result->sourceHandle);
            $this->assertSame('solution_inactive', $result->metadata['error_code']);
            $this->assertSame('solution_inactive: feedback', $result->errorMessage, 'The flow log keeps this field, so the reason is visible there.');
            $this->assertSame($built, $this->calls->constructions, 'The handler was not even built.');
        });

        $this->assertCount(1, $this->calls->calls, 'Only the call made while the Solution was on reached the handler.');
    }

    public function test_a_tenant_that_never_activated_the_solution_is_refused(): void
    {
        $this->inTenant($this->beta, function (): void {
            $result = $this->runNode();

            $this->assertSame('error', $result->sourceHandle);
            $this->assertSame('solution_inactive', $result->metadata['error_code']);
        });

        $this->assertSame([], $this->calls->calls);
    }

    public function test_two_tenants_in_one_process_get_their_own_answer(): void
    {
        $this->inTenant($this->alpha, function (): void {
            $this->activate();

            $this->assertSame('success', $this->runNode()->sourceHandle);

            // A job that switches tenant mid-way, then returns.
            $this->inTenant($this->beta, fn () => $this->assertSame('error', $this->runNode()->sourceHandle));

            $this->assertSame('success', $this->runNode()->sourceHandle);
        });

        $this->inTenant($this->beta, fn () => $this->assertSame('error', $this->runNode()->sourceHandle));
        $this->assertCount(2, $this->calls->calls);
        $this->assertSame(['tenant-alpha', 'tenant-alpha'], array_column($this->calls->calls, 'tenant_id'));
    }

    public function test_the_calls_outside_any_tenant_are_refused(): void
    {
        $result = $this->app->make(HandlerTransport::class)->execute(
            new CallRequest(target: 'feedback.submit'),
            $this->callContext(),
        );

        $this->assertFalse($result->success);
        $this->assertSame('solution_inactive', $result->errorCode);
    }

    public function test_the_builder_test_call_follows_the_same_rule(): void
    {
        $config = ['transport' => 'handler', 'target' => 'feedback.submit', 'parameters' => ['rating' => 4]];

        $this->inTenant($this->alpha, function () use ($config): void {
            $refused = $this->app->make(CallTester::class)->run($config, []);
            $this->assertFalse($refused['success']);
            $this->assertSame('solution_inactive', $refused['error_code']);

            $this->activate();

            $reached = $this->app->make(CallTester::class)->run($config, []);
            $this->assertTrue($reached['success']);
            $this->assertSame(['rating' => 4], $reached['body']['echo']);
        });
    }

    public function test_the_builder_lists_core_actions_and_only_the_active_solutions_actions(): void
    {
        $this->app->make(ActionHandlerRegistry::class)->register(CoreProbeAction::class);
        $builder = $this->app->make(LoadBuilderFlowService::class);

        $this->inTenant($this->alpha, function () use ($builder): void {
            $this->assertSame(['core.probe'], $builder->availableActions());

            $this->activate();

            $this->assertSame(['feedback.submit', 'feedback.score', 'core.probe'], $builder->availableActions());
            $this->inTenant($this->beta, fn () => $this->assertSame(['core.probe'], $builder->availableActions()));
        });
    }

    public function test_the_handler_is_built_in_the_scope_of_each_call(): void
    {
        $transport = $this->app->make(HandlerTransport::class);
        $request   = new CallRequest(target: 'feedback.submit');
        $scopeOf   = fn (): string => $this->inTenant($this->alpha, fn () => $transport->execute($request, $this->callContext())->payload['scope']);

        $this->inTenant($this->alpha, fn () => $this->activate());

        $first = $this->inTenant($this->alpha, fn (): array => [
            $transport->execute($request, $this->callContext())->payload['scope'],
            $transport->execute($request, $this->callContext())->payload['scope'],
        ]);
        $this->assertSame($first[0], $first[1], 'Within a job the handler gets the job\'s scoped service.');

        // The next queue job: the container drops its scoped instances, the long-lived transport stays.
        $this->app->forgetScopedInstances();

        $this->assertNotSame($first[0], $scopeOf(), 'The next job\'s handler is built with the next job\'s scoped service.');
    }

    public function test_a_refused_call_still_spends_one_call_execution(): void
    {
        $meter = FakeUsageMeter::allowing();
        $this->app->instance(UsageMeterInterface::class, $meter);

        $this->inTenant($this->alpha, function () use ($meter): void {
            $this->assertSame('error', $this->runNode()->sourceHandle);

            $this->assertCount(1, $meter->units);
            $this->assertSame('call_executions', $meter->units[0]->key);
        });
    }

    public function test_a_call_over_the_volume_limit_is_refused_before_the_solution_is_asked(): void
    {
        $this->app->instance(UsageMeterInterface::class, FakeUsageMeter::denying(10, 10));

        $this->inTenant($this->alpha, function (): void {
            $this->activate();

            $result = $this->runNode();

            $this->assertSame('error', $result->sourceHandle);
            $this->assertSame('limit_reached', $result->metadata['error_type']);
        });

        $this->assertSame([], $this->calls->calls);
    }

    private function activate(): void
    {
        $this->app->make(SolutionActivator::class)->activate('feedback', ActivationActor::console());
    }

    private function runNode(): NodeExecutionResult
    {
        $tenantId = $this->app->make(TenantContextInterface::class)->get()->getId();

        return $this->app->make(CallNodeHandler::class)->execute(
            ['id' => 'call-1', 'config' => ['transport' => 'handler', 'target' => 'feedback.submit', 'parameters' => ['rating' => 5]]],
            [],
            new NodeExecutionContext(
                tenantId: $tenantId,
                contactId: 'contact-1',
                sessionId: 'session-1',
                nodeId: 'call-1',
                idempotencyKey: 'session-1:call-1:' . bin2hex(random_bytes(3)),
                platform: 'telegram',
            ),
        );
    }

    private function callContext(): CallContext
    {
        $tenantId = $this->app->make(TenantContextInterface::class)->isResolved() ? $this->app->make(TenantContextInterface::class)->get()->getId() : 'none';

        return new CallContext(tenantId: $tenantId, contactId: 'c-1', sessionId: 's-1', nodeId: 'n-1', idempotencyKey: 's-1:n-1:1');
    }

    private function inTenant(RuntimeTenant $tenant, callable $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant($tenant, $callback(...));
    }
}

final class CoreProbeAction implements ActionHandlerInterface
{
    public function id(): string
    {
        return 'core.probe';
    }

    public function version(): int
    {
        return 1;
    }

    public function handle(array $parameters, CallContext $context): mixed
    {
        return null;
    }
}
