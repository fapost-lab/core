<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Console\Platform\PlatformMigratorInterface;
use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Call\CallTester;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Services\LoadBuilderFlowService;
use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Providers\SolutionServiceProvider;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Http\Shell\SolutionUi;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Mockery;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\Feature\FeatureTestCase;
use Tests\Fixtures\Solution\FeedbackCalls;
use Tests\Fixtures\Solution\FeedbackProbe;
use Tests\Support\FakeVendor;
use Tests\Support\InMemorySolutionActivationStore;
use Tests\Support\InstallsShowcaseSolution;

/**
 * The whole life of a Solution in one booted application, from the image to the tenant that switched it off: two
 * fixture packages installed (`feedback`, which brings actions, and `showcase`, which brings console UI),
 * `platform:update` validating and publishing them, activation for tenant alpha, a real flow whose `call` node reaches
 * the action through the FlowEngine, the builder's test call, the console pages, then tenant beta answering
 * `solution_inactive` throughout, deactivation, reactivation, and a worker serving both tenants job after job.
 *
 * Each link has unit tests of its own; this is the proof that they hold together. On PostgreSQL the second tenant has
 * a schema of its own and the real activation store is used throughout. SQLite has no schemas, so there the store is
 * the in-memory one that keeps a list per tenant id ({@see InMemorySolutionActivationStore}); the commands test below
 * uses the real store on both.
 */
final class SolutionLifecycleTest extends FeatureTestCase
{
    use InstallsShowcaseSolution;

    private const string BETA_ID = '00000000-0000-0000-0000-0000000000b2';

    private FeedbackCalls $calls;

    private TenantInterface $alpha;

    private int $migrations = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);

        $this->calls = new FeedbackCalls();
        $this->app->instance(FeedbackCalls::class, $this->calls);
        $this->app->scoped(FeedbackProbe::class);

        $this->migrations = 0;
        $this->app->instance(PlatformMigratorInterface::class, new class ($this) implements PlatformMigratorInterface {
            public function __construct(private readonly SolutionLifecycleTest $test)
            {
            }

            public function migrate(OutputInterface $output): bool
            {
                $this->test->recordMigration();

                return true;
            }
        });

        // What the image holds and a booting application does with it: the two packages, their providers, their
        // routes, and (after `installShowcase`) the actions the feedback provider registers.
        $this->installShowcase(publish: false, alsoInstalled: [FakeVendor::solutionComposer()]);
        (new SolutionServiceProvider($this->app))->registerExtensions($this->app->make(InstalledSolutions::class)->all());

        $alpha = $this->app->make(TenantRepositoryInterface::class)->findBySlug('main');
        $this->assertNotNull($alpha);
        $this->alpha = $alpha;

        Route::middleware('admin')->get('admin/_probe', fn () => Inertia::render('Auth/Login', ['action' => 'probe']));
    }

    protected function tearDown(): void
    {
        $this->removeShowcase();

        parent::tearDown();
    }

    public function recordMigration(): void
    {
        $this->migrations++;
    }

    public function test_a_solution_goes_from_the_image_to_a_tenant_and_back_without_touching_the_others(): void
    {
        $beta = $this->secondTenant();

        // The image: the update validates, then migrates, publishes and caches.
        $this->artisan('platform:update', ['--validate-only' => true])
            ->expectsOutputToContain('Solution manifests are valid (2 installed)')
            ->assertExitCode(0);
        $this->assertSame(0, $this->migrations);
        $this->assertFileDoesNotExist($this->app->make(SolutionCache::class)->path());

        $this->artisan('platform:update')->expectsOutputToContain('Solution catalog cached')->assertExitCode(0);
        $this->assertSame(1, $this->migrations);
        $this->assertSame(['feedback', 'showcase'], array_map(static fn ($solution): string => $solution->id, $this->app->make(SolutionCache::class)->read()));
        $this->forgetSolutionState();

        // Installed is not active: nobody has switched anything on.
        $this->assertSame('solution_inactive: feedback', $this->flowResult($this->alpha)['call_error']);
        $this->assertCallRefused($this->alpha);
        $this->assertSame([], $this->calls->calls);
        $this->assertConsoleOf(visible: false);

        // Tenant alpha switches both on.
        $this->activate($this->alpha, 'feedback', 'showcase');

        $reached = $this->flowResult($this->alpha);
        $this->assertSame(['end_status' => 'success', 'call_error' => null], $reached);
        $this->assertCount(1, $this->calls->calls);
        $this->assertSame($this->alpha->getId(), $this->calls->calls[0]['tenant_id'], 'The handler sees the tenant the flow runs for.');
        $this->assertSame(['rating' => 5], $this->calls->calls[0]['parameters']);

        $this->inTenant($this->alpha, function (): void {
            $tested = $this->app->make(CallTester::class)->run($this->callConfig(), []);
            $this->assertTrue($tested['success'], 'The builder test call reaches the action too.');
            $this->assertSame(['rating' => 5], $tested['body']['echo']);
            $this->assertSame(['feedback', 'showcase'], $this->app->make(ActiveSolutions::class)->ids());
            $this->assertSame(['feedback.submit', 'feedback.score'], $this->app->make(LoadBuilderFlowService::class)->availableActions());
        });
        $this->assertCount(2, $this->calls->calls);
        $this->assertConsoleOf(visible: true);
        $this->assertServedTo($this->alpha, true);

        // Tenant beta, in the same process, has activated nothing.
        $this->assertServedTo($beta, false);
        $refused = $this->flowResult($beta);
        $this->assertSame(['end_status' => 'failed', 'call_error' => 'solution_inactive: feedback'], $refused);
        $this->assertCallRefused($beta);
        $this->inTenant($beta, function (): void {
            $this->assertSame([], $this->app->make(ActiveSolutions::class)->ids());
            $this->assertSame([], $this->app->make(LoadBuilderFlowService::class)->availableActions());
        });
        $this->assertCount(2, $this->calls->calls, 'The handler was not called for beta.');

        // A worker: one process, one job after another, the container dropping its scoped instances in between.
        foreach ([[$this->alpha, 'success'], [$beta, 'failed'], [$this->alpha, 'success'], [$beta, 'failed']] as [$tenant, $endStatus]) {
            $this->app->forgetScopedInstances();

            $this->assertSame($endStatus, $this->flowResult($tenant)['end_status'], 'Job for ' . $tenant->getSlug());
        }
        $this->assertCount(4, $this->calls->calls);
        $this->assertSame(array_fill(0, 4, $this->alpha->getId()), array_column($this->calls->calls, 'tenant_id'));

        // Alpha switches off: its flows are refused at once, and the handler is not even built.
        $this->deactivate($this->alpha, 'feedback', 'showcase');
        $built = $this->calls->constructions;

        $this->assertSame(['end_status' => 'failed', 'call_error' => 'solution_inactive: feedback'], $this->flowResult($this->alpha));
        $this->assertCallRefused($this->alpha);
        $this->assertSame($built, $this->calls->constructions);
        $this->assertCount(4, $this->calls->calls);
        $this->assertConsoleOf(visible: false);

        // And on again.
        $this->activate($this->alpha, 'feedback', 'showcase');

        $this->assertSame('success', $this->flowResult($this->alpha)['end_status']);
        $this->assertCount(5, $this->calls->calls);
        $this->assertConsoleOf(visible: true);

        // The other way round: beta's choice does not reach alpha.
        $this->activate($beta, 'feedback');
        $this->assertSame('success', $this->flowResult($beta)['end_status']);
        $this->assertSame($beta->getId(), $this->calls->calls[5]['tenant_id']);

        $this->deactivate($beta, 'feedback');
        $this->assertSame('failed', $this->flowResult($beta)['end_status']);
        $this->assertSame('success', $this->flowResult($this->alpha)['end_status']);
        $this->assertCount(7, $this->calls->calls);

        // A worker never serving alpha again, and a job switching tenants half way, agree.
        $this->app->forgetScopedInstances();
        $nested = $this->inTenant($this->alpha, fn (): array => [
            $this->app->make(ActiveSolutions::class)->ids(),
            $this->inTenant($beta, fn (): array => $this->app->make(ActiveSolutions::class)->ids()),
            $this->app->make(ActiveSolutions::class)->ids(),
        ]);
        $this->assertSame([['feedback', 'showcase'], [], ['feedback', 'showcase']], $nested);
    }

    public function test_the_operator_commands_drive_the_same_chain_on_the_real_store(): void
    {
        $this->artisan('platform:update')->assertExitCode(0);
        $this->forgetSolutionState();

        $this->assertSame('failed', $this->flowResult($this->alpha)['end_status']);

        $this->artisan('solutions:activate', ['solution' => 'feedback', '--tenant' => 'main'])->assertExitCode(0);
        $this->forgetSolutionState();

        $this->assertSame('success', $this->flowResult($this->alpha)['end_status']);
        $this->assertCount(1, $this->calls->calls);

        $this->artisan('solutions:deactivate', ['solution' => 'feedback', '--tenant' => 'main'])->assertExitCode(0);
        $this->forgetSolutionState();

        $this->assertSame(['end_status' => 'failed', 'call_error' => 'solution_inactive: feedback'], $this->flowResult($this->alpha));
        $this->assertCount(1, $this->calls->calls);
        $this->assertSame(
            ['activated', 'deactivated'],
            DB::table('solution_activation_events')->where('solution_id', 'feedback')->orderBy('id')->pluck('action')->all(),
        );
    }

    /**
     * The tenant that never activates anything for the first part of the scenario. On PostgreSQL it is a real tenant
     * with a schema of its own; on SQLite, which has none, a tenant id the in-memory store keeps a list for.
     */
    private function secondTenant(): TenantInterface
    {
        if ('pgsql' !== DB::connection('landlord')->getDriverName() || 'pgsql' !== DB::connection((string) config('tenancy.tenant_connection'))->getDriverName()) {
            $store = new InMemorySolutionActivationStore(fn (): TenantContextInterface => $this->app->make(TenantContextInterface::class));
            $this->app->instance(SolutionActivationStoreInterface::class, $store);
            $this->app->forgetInstance(ActiveSolutions::class);

            return new RuntimeTenant(self::BETA_ID, 'beta', 'beta');
        }

        // Provisioning names the schema from the slug and, in a host-mode install, from the host.
        $previous = config('tenancy.resolution');
        config(['tenancy.resolution' => 'host']);
        $webhooks = Mockery::mock(ChannelWebhookRegistryInterface::class);
        $webhooks->shouldReceive('warmup');
        $this->app->instance(ChannelWebhookRegistryInterface::class, $webhooks);

        try {
            $id = $this->app->make(TenantProvisioningService::class)
                ->provision('lifecycle-beta', 'admin@lifecycle-beta.test', 'S3cret-p@ss-value', 'Admin')
                ->getId();
        } finally {
            config(['tenancy.resolution' => $previous]);
        }

        return Tenant::on('landlord')->findOrFail($id);
    }

    private function inTenant(TenantInterface $tenant, callable $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant($tenant, $callback(...));
    }

    private function activate(TenantInterface $tenant, string ...$solutions): void
    {
        $this->inTenant($tenant, function () use ($solutions): void {
            foreach ($solutions as $solution) {
                $this->app->make(SolutionActivator::class)->activate($solution, ActivationActor::console());
            }
        });
        $this->forgetSolutionState();
    }

    private function deactivate(TenantInterface $tenant, string ...$solutions): void
    {
        $this->inTenant($tenant, function () use ($solutions): void {
            foreach ($solutions as $solution) {
                $this->app->make(SolutionActivator::class)->deactivate($solution, ActivationActor::console());
            }
        });
        $this->forgetSolutionState();
    }

    /**
     * @return array<string, mixed>
     */
    private function callConfig(): array
    {
        return ['transport' => 'handler', 'target' => 'feedback.submit', 'parameters' => ['rating' => 5]];
    }

    /**
     * Runs a one-node flow for the tenant through the FlowEngine, as a queue job would: a `call` node to the action,
     * ending as `success` when it is reached and as `failed` when it is refused.
     *
     * @return array{end_status: string|null, call_error: string|null}
     */
    private function flowResult(TenantInterface $tenant): array
    {
        return $this->inTenant($tenant, function () use ($tenant): array {
            $tenantId  = $tenant->getId();
            $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
            $contact   = Contact::factory()->forTenant($tenantId)->create();
            $this->app->make(CurrentAssistantInterface::class)->set($assistant);

            $definition = FlowDefinition::query()->create([
                'tenant_id' => $tenantId,
                'flow_id'   => (string) Str::uuid(),
                'version'   => 1,
                'name'      => 'Feedback',
                'nodes'     => [
                    ['id' => 'call', 'type' => 'call', 'version' => 1, 'config' => $this->callConfig()],
                    ['id' => 'reached', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
                    ['id' => 'refused', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'failed']],
                ],
                'edges' => [
                    ['id' => 'e1', 'from' => 'call', 'to' => 'reached', 'handle' => 'success'],
                    ['id' => 'e2', 'from' => 'call', 'to' => 'refused', 'handle' => 'error'],
                ],
                'is_active' => true,
            ]);

            $session = $this->app->make(FlowEngineInterface::class)->start($definition, $contact);
            $log     = DB::table('flow_logs')->where('session_id', $session->getKey())->where('node_id', 'call')->first();

            return [
                'end_status' => null === $session->end_status ? null : (string) $session->end_status,
                'call_error' => null === $log?->error ? null : (string) (json_decode((string) $log->error, true)['message'] ?? $log->error),
            ];
        });
    }

    private function assertCallRefused(TenantInterface $tenant): void
    {
        $this->inTenant($tenant, function (): void {
            $result = $this->app->make(CallTester::class)->run($this->callConfig(), []);

            $this->assertFalse($result['success']);
            $this->assertSame('solution_inactive', $result['error_code']);
        });
    }

    /**
     * What the console shows of `showcase` to a request of the default tenant (`main`, tenant alpha): its page and its
     * entry in the applications. The other tenant's side is {@see assertServedTo()}: a request is routed to a tenant by
     * its host, and the routes of this suite are bound to the default tenant's.
     */
    private function assertConsoleOf(bool $visible): void
    {
        $this->actingAs($this->admin());

        $applications = $this->props($this->get($this->panelUrl('/admin/_probe')))['applications']['items'];

        if ($visible) {
            $this->get($this->panelUrl('/apps/showcase/board'))->assertOk();
            $this->assertContains('showcase', array_column($applications, 'key'), "the application is listed");

            return;
        }

        $this->get($this->panelUrl('/apps/showcase/board'))->assertNotFound();
        $this->assertNotContains('showcase', array_column($applications, 'key'), "the application is not listed");
    }

    /**
     * Whether the console would serve the Solution's UI to a request of this tenant: asked of the same `SolutionUi` the
     * shell uses, inside the tenant, as a request of its host would be.
     */
    private function assertServedTo(TenantInterface $tenant, bool $served): void
    {
        $this->forgetSolutionState();

        $this->assertSame($served, $this->inTenant($tenant, fn (): bool => $this->app->make(SolutionUi::class)->isServed('showcase')), 'Console UI of ' . $tenant->getSlug());

        $this->forgetSolutionState();
    }

    /**
     * @return array<string, mixed>
     */
    private function props(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }
}
