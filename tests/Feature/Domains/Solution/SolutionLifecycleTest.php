<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Console\Platform\PlatformMigratorInterface;
use App\Console\Platform\UnservedChannelsFinderInterface;
use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Call\CallTester;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Services\LoadBuilderFlowService;
use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Providers\SolutionServiceProvider;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionUiCompatibility;
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
use App\Jobs\Solution\PrepareSolutionSchemaJob;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Flow\Contracts\StateNamespacesInterface;
use Fapost\Foundation\Solution\Contracts\SolutionEntitlementInterface;
use Fapost\Foundation\Solution\DTO\SolutionEntitlement;
use Fapost\Foundation\Solution\DTO\SolutionGrant;
use Fapost\Foundation\Solution\Enums\SolutionGrantSource;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Mockery;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\Feature\FeatureTestCase;
use Tests\Fixtures\Solution\FeedbackCalls;
use Tests\Fixtures\Solution\FeedbackProbe;
use Tests\Support\FakeSolutionEntitlement;
use Tests\Support\FakeVendor;
use Tests\Support\InMemorySolutionActivationStore;
use Tests\Support\InstallsShowcaseSolution;
use Tests\Support\NoUnservedChannels;

/**
 * The whole life of a Solution in one booted application, from the image to the tenant that switched it off: two
 * fixture packages installed (`feedback`, which brings actions, and `showcase`, which brings console UI),
 * `platform:update` validating and publishing them, activation for tenant alpha, a real flow whose `call` node reaches
 * the action through the FlowEngine, the builder's test call, the console pages, then tenant beta answering
 * `solution_inactive` throughout, deactivation, reactivation, and a worker serving both tenants job after job. A third
 * test takes the entitlement away and gives it back: computed, with nothing written. Another moves the kit Core ships
 * past the console UI's range: the UI is hidden, the Solution keeps running.
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

    private const string CREATE_ENTRIES = '2026_11_16_000001_create_feedback_entries_table';

    private const string CREATE_SCORES = '2026_11_16_000002_create_feedback_scores_table';

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
        $this->app->instance(UnservedChannelsFinderInterface::class, new NoUnservedChannels());
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

    public function test_a_node_of_the_solution_runs_for_the_tenant_that_activated_it_and_takes_the_error_exit_for_the_other(): void
    {
        $beta = $this->secondTenant();

        $this->artisan('platform:update')->assertExitCode(0);
        $this->forgetSolutionState();
        $this->activate($this->alpha, 'feedback');

        // The namespace and the node type are the image's, whoever has the Solution on.
        $this->assertSame('feedback', $this->app->make(NodeHandlerRegistryInterface::class)->ownerOf('feedback.collect'));
        $this->assertSame(['system', 'flow', 'rag', 'module', 'contact', 'call', 'feedback'], $this->app->make(StateNamespacesInterface::class)->all());
        $this->assertSame('feedback', $this->app->make(StateNamespacesInterface::class)->ownerOf('feedback'));

        $built = $this->calls->constructions;

        $ran = $this->nodeFlowResult($this->alpha);
        $this->assertSame(['end' => 'reached', 'error' => null, 'state' => ['last_score' => 4]], $ran);
        $this->assertCount(1, $this->calls->calls);
        $this->assertSame('feedback.collect', $this->calls->calls[0]['action']);
        $this->assertSame($this->alpha->getId(), $this->calls->calls[0]['tenant_id']);
        $this->assertSame($built + 1, $this->calls->constructions);

        $refused = $this->nodeFlowResult($beta);
        $this->assertSame(['end' => 'refused', 'error' => 'solution_inactive: feedback', 'state' => null], $refused);
        $this->assertCount(1, $this->calls->calls, 'The handler was not built for beta.');
        $this->assertSame($built + 1, $this->calls->constructions);

        // The worker serves them alternately, and switching alpha off takes its next run to the error exit too.
        foreach ([[$this->alpha, 'reached'], [$beta, 'refused'], [$this->alpha, 'reached']] as [$tenant, $end]) {
            $this->app->forgetScopedInstances();

            $this->assertSame($end, $this->nodeFlowResult($tenant)['end'], 'Job for ' . $tenant->getSlug());
        }

        $this->deactivate($this->alpha, 'feedback');
        $this->assertSame(['end' => 'refused', 'error' => 'solution_inactive: feedback', 'state' => null], $this->nodeFlowResult($this->alpha));
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

    public function test_losing_the_entitlement_stops_the_solution_everywhere_and_getting_it_back_resumes_it_with_nothing_written(): void
    {
        $operator = new FakeSolutionEntitlement();
        $this->app->instance(SolutionEntitlementInterface::class, $operator);

        $this->artisan('platform:update')->assertExitCode(0);
        $this->artisan('solutions:activate', ['solution' => 'feedback', '--tenant' => 'main'])->assertExitCode(0);
        $this->artisan('solutions:activate', ['solution' => 'showcase', '--tenant' => 'main'])->assertExitCode(0);
        $this->forgetSolutionState();

        $this->assertSame('success', $this->flowResult($this->alpha)['end_status']);
        $this->assertConsoleOf(visible: true);
        $journal = DB::table('solution_activation_events')->count();

        // The operator stops including both: the flow is refused, the builder and the console lose them.
        $operator->default = SolutionEntitlement::only([]);
        $this->forgetSolutionState();

        $this->assertSame(['end_status' => 'failed', 'call_error' => 'solution_inactive: feedback'], $this->flowResult($this->alpha));
        $this->assertCallRefused($this->alpha);
        $this->inTenant($this->alpha, fn () => $this->assertSame([], $this->app->make(LoadBuilderFlowService::class)->availableActions()));
        $this->assertConsoleOf(visible: false);
        $this->assertServedTo($this->alpha, false);
        $this->assertCount(1, $this->calls->calls);

        // A command cannot turn it on either.
        $this->artisan('solutions:deactivate', ['solution' => 'showcase', '--tenant' => 'main'])->assertExitCode(0);
        $this->artisan('solutions:activate', ['solution' => 'showcase', '--tenant' => 'main'])->assertExitCode(1);

        // The rows keep the tenant's intent; nothing was journaled for the loss.
        $this->assertSame('active', DB::table('solution_activations')->where('solution_id', 'feedback')->value('status'));
        $this->assertSame($journal + 1, DB::table('solution_activation_events')->count(), 'Only the explicit deactivation.');

        // The entitlement comes back: feedback runs again by itself; showcase, turned off meanwhile, stays off.
        $operator->default = null;
        $this->forgetSolutionState();

        $this->assertSame('success', $this->flowResult($this->alpha)['end_status']);
        $this->assertCount(2, $this->calls->calls);
        $this->assertConsoleOf(visible: false);
        $this->assertSame($journal + 1, DB::table('solution_activation_events')->count());
    }

    public function test_a_plan_grant_runs_the_solution_everywhere_with_nothing_stored_and_its_end_stops_it(): void
    {
        $operator = new FakeSolutionEntitlement();
        $this->app->instance(SolutionEntitlementInterface::class, $operator);

        $this->artisan('platform:update')->assertExitCode(0);
        $operator->default = SolutionEntitlement::grants([new SolutionGrant('feedback', SolutionGrantSource::Plan), new SolutionGrant('showcase', SolutionGrantSource::Addon)]);
        $this->forgetSolutionState();

        // Nothing was turned on: the grant alone runs the flow action, the builder palette and the console.
        $this->assertSame(0, DB::table('solution_activations')->count());
        $this->assertSame('success', $this->flowResult($this->alpha)['end_status']);
        $this->inTenant($this->alpha, fn () => $this->assertNotSame([], $this->app->make(LoadBuilderFlowService::class)->availableActions()));
        $this->assertConsoleOf(visible: true);

        // The tenant cannot turn a granted Solution off.
        $this->artisan('solutions:deactivate', ['solution' => 'feedback', '--tenant' => 'main'])->assertExitCode(1);

        // The plan no longer includes it: stopped everywhere, with nothing written.
        $operator->default = SolutionEntitlement::grants([]);
        $this->forgetSolutionState();

        $this->assertSame(['end_status' => 'failed', 'call_error' => 'solution_inactive: feedback'], $this->flowResult($this->alpha));
        $this->assertConsoleOf(visible: false);
        $this->assertSame(0, DB::table('solution_activations')->count());
        $this->assertSame(0, DB::table('solution_activation_events')->count());
    }

    public function test_a_core_upgrade_past_its_kit_range_hides_the_console_ui_and_keeps_the_solution_running(): void
    {
        $this->artisan('platform:update')->assertExitCode(0);
        $this->activate($this->alpha, 'feedback', 'showcase');
        $this->assertConsoleOf(visible: true);

        // Core now ships a kit the showcase bundle (`^0.1`) was not built for.
        config(['solutions.console_runtime.kit' => '0.2.0']);
        $this->app->forgetInstance(SolutionUiCompatibility::class);
        $this->forgetSolutionState();

        // The activation, the flows and the builder are untouched.
        $this->assertSame('success', $this->flowResult($this->alpha)['end_status']);
        $this->inTenant($this->alpha, fn () => $this->assertSame(['feedback', 'showcase'], $this->app->make(ActiveSolutions::class)->ids()));
        $this->assertSame('active', DB::table('solution_activations')->where('solution_id', 'showcase')->value('status'));

        // The console UI is not served: its page says it needs an update, its application is marked.
        $this->assertServedTo($this->alpha, false);
        $this->actingAs($this->admin());
        $this->get($this->panelUrl('/apps/showcase/board'))->assertStatus(503);
        $showcase = collect($this->props($this->get($this->panelUrl('/admin/_probe')))['applications']['items'])->firstWhere('key', 'showcase');
        $this->assertTrue($showcase['needsUpdate']);

        // A build that fits again (here: the platform back on a kit inside the range) is served at once.
        config(['solutions.console_runtime.kit' => '0.1.1']);
        $this->app->forgetInstance(SolutionUiCompatibility::class);
        $this->forgetSolutionState();

        $this->assertConsoleOf(visible: true);
        $this->assertServedTo($this->alpha, true);
    }

    public function test_a_solution_that_keeps_tables_gets_them_in_the_schema_of_the_tenant_that_activates_it_only(): void
    {
        $beta = $this->secondTenant();
        $this->installFeedbackWithTables();
        $this->artisan('platform:update')->assertExitCode(0);
        $this->forgetSolutionState();

        // The compiled catalog carries the newest migration the package ships.
        $feedback = collect($this->app->make(SolutionCache::class)->read())->firstWhere('id', 'feedback');
        $this->assertSame(self::CREATE_ENTRIES, $feedback?->latestMigration);

        // Installed is not prepared: no schema holds a table of it.
        $this->assertSame([], $this->feedbackTables($this->alpha));
        $this->assertSame([], $this->feedbackTables($beta));

        $this->activate($this->alpha, 'feedback');

        $this->assertSame(['feedback_entries'], $this->feedbackTables($this->alpha));
        $this->assertSame(['feedback' => self::CREATE_ENTRIES], $this->footprints($this->alpha));
        $this->assertSame('success', $this->flowResult($this->alpha)['end_status']);

        if ($this->hasTenantSchemas()) {
            $this->assertSame([], $this->feedbackTables($beta), 'The other tenant\'s schema was not touched.');
            $this->assertSame([], $this->footprints($beta));
            $this->assertSame('solution_inactive: feedback', $this->flowResult($beta)['call_error']);
        }
    }

    public function test_a_granted_solution_answers_not_ready_until_the_queued_job_has_prepared_its_tables(): void
    {
        // The job looks its tenant up in the landlord: a real tenant, with a schema of its own on PostgreSQL.
        $beta = $this->hasTenantSchemas() ? $this->secondTenant() : $this->alpha;
        $this->installFeedbackWithTables();
        Bus::fake([PrepareSolutionSchemaJob::class]);
        $operator                          = new FakeSolutionEntitlement();
        $operator->answers[$beta->getId()] = SolutionEntitlement::grants([new SolutionGrant('feedback', SolutionGrantSource::Plan)]);
        $this->app->instance(SolutionEntitlementInterface::class, $operator);
        $this->artisan('platform:update')->assertExitCode(0);
        $this->forgetSolutionState();

        // The grant runs it with nothing stored, and nobody pressed a button: its tables are not there.
        $this->assertSame(['end_status' => 'failed', 'call_error' => 'solution_not_ready: feedback'], $this->flowResult($beta));
        $this->inTenant($beta, function (): void {
            $tested = $this->app->make(CallTester::class)->run($this->callConfig(), []);

            $this->assertFalse($tested['success']);
            $this->assertSame('solution_not_ready', $tested['error_code']);
        });
        $this->assertSame([], $this->calls->calls, 'The handler was not called.');
        $this->assertSame([], $this->feedbackTables($beta), 'Asking never runs DDL.');
        Bus::assertDispatched(PrepareSolutionSchemaJob::class, static fn (PrepareSolutionSchemaJob $job): bool => $job->tenantId === $beta->getId() && 'feedback' === $job->solutionId);

        // The worker runs the queued job; the next step goes through.
        $job = Bus::dispatched(PrepareSolutionSchemaJob::class)->first();
        $this->assertInstanceOf(PrepareSolutionSchemaJob::class, $job);
        $this->app->call([$job, 'handle']);
        $this->forgetSolutionState();

        $this->assertSame(['end_status' => 'success', 'call_error' => null], $this->flowResult($beta));
        $this->assertSame($beta->getId(), $this->calls->calls[0]['tenant_id']);
        $this->assertSame(['feedback_entries'], $this->feedbackTables($beta));
        $this->assertSame(0, $this->inTenant($beta, fn (): int => DB::table('solution_activations')->count()), 'Nothing was stored for a grant.');

        if ($this->hasTenantSchemas()) {
            $this->assertSame([], $this->feedbackTables($this->alpha), 'The main tenant never ran it.');
            $this->assertSame([], $this->footprints($this->alpha));
        }
    }

    public function test_an_upgrade_through_solutions_migrate_reaches_the_schemas_that_have_the_tables_and_no_others(): void
    {
        $this->installFeedbackWithTables();
        Bus::fake([PrepareSolutionSchemaJob::class]);
        $this->artisan('platform:update')->assertExitCode(0);
        $this->forgetSolutionState();

        // Alpha runs the Solution. On PostgreSQL a second tenant turned it on and off again (its tables stay), and a
        // third never used it.
        $this->activate($this->alpha, 'feedback');
        $deactivated = $untouched = null;

        if ($this->hasTenantSchemas()) {
            $deactivated = $this->secondTenant('lifecycle-dee');
            $untouched   = $this->secondTenant('lifecycle-nil');
            $this->activate($deactivated, 'feedback');
            $this->deactivate($deactivated, 'feedback');
        }

        // The package is upgraded (a new image, `platform:update` caching it): every schema is behind, and what runs
        // is refused until it is prepared.
        $this->shipFeedbackUpgrade();

        $this->assertSame('solution_not_ready: feedback', $this->flowResult($this->alpha)['call_error']);
        $this->assertSame(['feedback_entries'], $this->feedbackTables($this->alpha));

        // The second half of `platform:update`.
        $command = $this->artisan('solutions:migrate')->expectsOutputToContain('✔ main feedback: ' . self::CREATE_SCORES);

        if (null !== $deactivated) {
            $command->expectsOutputToContain('✔ lifecycle-dee feedback: ' . self::CREATE_SCORES);
        }

        // A command held in a variable would run only when it is destroyed.
        $command->assertExitCode(0)->run();
        $this->forgetSolutionState();

        $this->assertSame(['feedback_entries', 'feedback_scores'], $this->feedbackTables($this->alpha));
        $this->assertSame(['feedback' => self::CREATE_SCORES], $this->footprints($this->alpha));
        $this->assertSame('success', $this->flowResult($this->alpha)['end_status']);

        if (null !== $deactivated && null !== $untouched) {
            $this->assertSame(['feedback_entries', 'feedback_scores'], $this->feedbackTables($deactivated), 'Deactivated, but its tables stay current.');
            $this->assertSame(['feedback' => self::CREATE_SCORES], $this->footprints($deactivated));
            $this->assertSame([], $this->feedbackTables($untouched), 'No footprint, no tables: an upgrade never creates them.');
            $this->assertSame([], $this->footprints($untouched));
        }
    }

    /**
     * The tenant that never activates anything for the first part of the scenario. On PostgreSQL it is a real tenant
     * with a schema of its own; on SQLite, which has none, a tenant id the in-memory store keeps a list for.
     */
    private function secondTenant(string $slug = 'lifecycle-beta'): TenantInterface
    {
        if (!$this->hasTenantSchemas()) {
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
                ->provision($slug, 'admin@' . $slug . '.test', 'S3cret-p@ss-value', 'Admin')
                ->getId();
        } finally {
            config(['tenancy.resolution' => $previous]);
        }

        return Tenant::on('landlord')->findOrFail($id);
    }

    /**
     * Whether every tenant has a schema of its own (PostgreSQL); SQLite has one database for all of them.
     */
    private function hasTenantSchemas(): bool
    {
        return 'pgsql' === DB::connection('landlord')->getDriverName() && 'pgsql' === DB::connection((string) config('tenancy.tenant_connection'))->getDriverName();
    }

    /**
     * Installs the fixture packages again, `feedback` now keeping one table (`feedback_entries`) in the tenant schema.
     */
    private function installFeedbackWithTables(): void
    {
        $this->installShowcase(
            publish: false,
            alsoInstalled: [FakeVendor::solutionComposer(['migrations' => 'database/migrations'])],
            prepareVendor: function (FakeVendor $vendor): void {
                $directory = $vendor->path . '/acme/fapost-feedback/database/migrations';
                mkdir($directory, 0o777, true);
                file_put_contents($directory . '/' . self::CREATE_ENTRIES . '.php', $this->createTableMigration('feedback_entries'));
            },
        );
    }

    /**
     * What a new image does to `feedback`: one more migration in its package, `platform:update` caching the catalog,
     * and the processes that start afterwards reading it.
     */
    private function shipFeedbackUpgrade(): void
    {
        $directory = $this->showcaseVendor?->path . '/acme/fapost-feedback/database/migrations';
        file_put_contents($directory . '/' . self::CREATE_SCORES . '.php', $this->createTableMigration('feedback_scores'));

        $this->artisan('platform:update')->assertExitCode(0);
        $this->app->instance(InstalledSolutions::class, new InstalledSolutions($this->app->make(SolutionCache::class), $this->app->make(SolutionDiscoverer::class)));
        $this->app->forgetScopedInstances();
        $this->forgetSolutionState();
    }

    private function createTableMigration(string $table): string
    {
        return <<<PHP
            <?php

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class () extends Migration {
                public function up(): void
                {
                    Schema::create('{$table}', static function (Blueprint \$table): void {
                        \$table->uuid('id')->primary();
                    });
                }
            };
            PHP;
    }

    /**
     * Names of the tables `feedback` keeps in the tenant's own schema (not the other tenants').
     *
     * @return list<string>
     */
    private function feedbackTables(TenantInterface $tenant): array
    {
        return $this->inTenant($tenant, function () use ($tenant): array {
            $names = $this->hasTenantSchemas()
                ? DB::table('information_schema.tables')->where('table_schema', $tenant->getSchemaName())->pluck('table_name')->all()
                : array_column(Schema::getTables(), 'name');

            $names = array_values(array_filter(array_map(strval(...), $names), static fn (string $name): bool => str_starts_with($name, 'feedback_')));
            sort($names);

            return $names;
        });
    }

    /**
     * The newest migration of each Solution applied in the tenant's schema.
     *
     * @return array<string, string>
     */
    private function footprints(TenantInterface $tenant): array
    {
        /** @var array<string, string> $latest */
        $latest = $this->inTenant($tenant, fn (): array => DB::table('solution_schemas')->orderBy('solution_id')->pluck('latest_migration', 'solution_id')->all());

        return $latest;
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

    /**
     * Runs a flow with the Solution's `feedback.collect` node (score 4) for the tenant: the `default` exit leads to
     * an `end` named `reached`, the `error` exit to one named `refused`.
     *
     * @return array{end: string|null, error: string|null, state: array<string, mixed>|null} the end reached, the error
     *         the node logged and the `feedback` part of the session state
     */
    private function nodeFlowResult(TenantInterface $tenant): array
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
                'name'      => 'Feedback node',
                'nodes'     => [
                    ['id' => 'collect', 'type' => 'feedback.collect', 'version' => 1, 'config' => ['score' => 4]],
                    ['id' => 'reached', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
                    ['id' => 'refused', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'failed']],
                ],
                'edges' => [
                    ['id' => 'e1', 'from' => 'collect', 'to' => 'reached', 'handle' => 'default'],
                    ['id' => 'e2', 'from' => 'collect', 'to' => 'refused', 'handle' => 'error'],
                ],
                'is_active' => true,
            ]);

            $session = $this->app->make(FlowEngineInterface::class)->start($definition, $contact);
            $log     = DB::table('flow_logs')->where('session_id', $session->getKey())->where('node_id', 'collect')->first();
            $end     = DB::table('flow_logs')->where('session_id', $session->getKey())->whereIn('node_id', ['reached', 'refused'])->value('node_id');

            return [
                'end'   => null === $end ? null : (string) $end,
                'error' => null === $log?->error ? null : (string) (json_decode((string) $log->error, true)['message'] ?? $log->error),
                'state' => $session->state['feedback'] ?? null,
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
