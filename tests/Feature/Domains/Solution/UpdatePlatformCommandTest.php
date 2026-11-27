<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Console\Platform\PlatformMigratorInterface;
use App\Console\Platform\UnservedChannelsFinderInterface;
use App\Domains\Channels\ConnectorChannels;
use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Commands\CommandActionTypeRegistry;
use App\Domains\Flow\Commands\SolutionCommandRegistry;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionManifestValidator;
use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\Flow\Commands\SolutionCommandInterface;
use Fapost\Foundation\Solution\Manifest\ManifestParser;
use Tests\Fixtures\Connectors\Echo\EchoInboundAdapter;
use Tests\Fixtures\Connectors\Echo\EchoSender;
use Tests\Fixtures\Solution\Actions\FeedbackScoreAction;
use Tests\Fixtures\Solution\Actions\FeedbackSubmitAction;
use Tests\Fixtures\Solution\Commands\FeedbackCommand;
use Tests\Fixtures\Solution\Commands\FeedbackThankCommandType;
use Tests\Fixtures\Solution\Nodes\FeedbackCollectNode;
use Tests\Support\FakePlatformMigrator;
use Tests\Support\FakeVendor;
use Tests\TestCase;

/**
 * Deliberately not a database test: `--validate-only` must run where there is none.
 */
final class UpdatePlatformCommandTest extends TestCase
{
    private FakeVendor $vendor;

    private SolutionCache $cache;

    private FakePlatformMigrator $migrator;

    /** @var list<array{tenant: string, type: string, count: int}> */
    private array $unserved = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = new FakeVendor();
        $this->cache  = new SolutionCache(dirname($this->vendor->path) . '/fapost-solutions.php');

        $this->app->instance(SolutionCache::class, $this->cache);
        $this->app->instance(SolutionDiscoverer::class, new SolutionDiscoverer(
            new InstalledPackagesReader($this->vendor->path),
            new SolutionManifestValidator(new ManifestParser()),
        ));
        $this->unserved = [];
        $this->app->instance(UnservedChannelsFinderInterface::class, new class ($this) implements UnservedChannelsFinderInterface {
            public function __construct(private readonly UpdatePlatformCommandTest $test)
            {
            }

            public function find(): array
            {
                return $this->test->unserved();
            }
        });
        $this->migrator = new FakePlatformMigrator();
        $this->app->instance(PlatformMigratorInterface::class, $this->migrator);
    }

    protected function tearDown(): void
    {
        $this->vendor->cleanup();

        parent::tearDown();
    }

    /**
     * @return list<array{tenant: string, type: string, count: int}>
     */
    public function unserved(): array
    {
        return $this->unserved;
    }

    public function test_validate_only_passes_on_a_valid_manifest_and_touches_nothing(): void
    {
        $this->registerFeedbackActions();
        $this->vendor->package(FakeVendor::solutionComposer(), '1.2.0')->write();

        $this->artisan('platform:update', ['--validate-only' => true])
            ->expectsOutputToContain('Solution manifests are valid (1 installed)')
            ->expectsOutputToContain('acme/fapost-feedback')
            ->assertExitCode(0);

        $this->assertSame(0, $this->migrator->landlordRuns);
        $this->assertFileDoesNotExist($this->cache->path());
    }

    public function test_validate_only_fails_on_an_invalid_manifest_and_names_the_field(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(['id' => 'platform']))->write();

        $this->artisan('platform:update', ['--validate-only' => true])
            ->expectsOutputToContain('acme/fapost-feedback: extra.fapost.id: "platform" is reserved')
            ->assertExitCode(1);
    }

    public function test_validate_only_lists_the_installed_connectors(): void
    {
        $this->registerEchoChannel();
        $this->vendor->package(FakeVendor::connectorComposer(), '1.4.0')->write();

        $this->artisan('platform:update', ['--validate-only' => true])
            ->expectsOutputToContain('Connector manifests are valid (1 installed)')
            ->expectsOutputToContain('acme/fapost-connector-echo')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($this->cache->path());
    }

    public function test_validate_only_fails_on_an_invalid_connector_manifest(): void
    {
        $this->vendor->package(FakeVendor::connectorComposer(['channels' => null]))->write();

        $this->artisan('platform:update', ['--validate-only' => true])
            ->expectsOutputToContain('acme/fapost-connector-echo: extra.fapost.channels: required')
            ->assertExitCode(1);
    }

    public function test_a_connector_violation_stops_the_update_before_migrations_and_cache(): void
    {
        $this->registerFeedbackActions();
        $this->vendor->package(FakeVendor::solutionComposer())->package(FakeVendor::connectorComposer(['provider' => 'Acme\\Missing']))->write();

        $this->artisan('platform:update')
            ->expectsOutputToContain('1 manifest problem(s). Nothing was changed.')
            ->expectsOutputToContain('acme/fapost-connector-echo: extra.fapost.provider')
            ->assertExitCode(1);

        $this->assertSame(0, $this->migrator->landlordRuns);
        $this->assertFileDoesNotExist($this->cache->path());
    }

    public function test_a_valid_update_caches_the_connectors_with_the_solutions(): void
    {
        $this->registerFeedbackActions();
        $this->registerEchoChannel();
        $this->vendor->package(FakeVendor::solutionComposer())->package(FakeVendor::connectorComposer())->write();

        $this->artisan('platform:update')->assertExitCode(0);

        $this->assertSame('echo', $this->cache->connectors()[0]->id ?? null);
        $this->assertSame('feedback', $this->cache->read()[0]->id ?? null);
    }

    public function test_a_declared_channel_type_that_is_not_registered_fails_the_update_before_migrations_and_cache(): void
    {
        $this->vendor->package(FakeVendor::connectorComposer())->write();

        $this->artisan('platform:update')
            ->expectsOutputToContain('"echo" declares channel type "echo" but does not register it')
            ->expectsOutputToContain('Nothing was changed')
            ->assertExitCode(1);

        $this->assertSame(0, $this->migrator->landlordRuns);
        $this->assertFileDoesNotExist($this->cache->path());
    }

    public function test_channels_of_a_type_nothing_serves_are_warned_about_per_tenant_and_never_refuse_the_update(): void
    {
        $this->registerFeedbackActions();
        $this->vendor->package(FakeVendor::solutionComposer(), '1.2.0')->write();
        $this->unserved = [
            ['tenant' => 'acme', 'type' => 'viber', 'count' => 3],
            ['tenant' => 'globex', 'type' => 'viber', 'count' => 1],
        ];

        $this->artisan('platform:update')
            ->expectsOutputToContain('Tenant "acme" has 3 channel(s) of type "viber"')
            ->expectsOutputToContain('Tenant "globex" has 1 channel(s) of type "viber"')
            ->assertExitCode(0);

        $this->assertSame(1, $this->migrator->landlordRuns);
        $this->assertFileExists($this->cache->path());
    }

    public function test_a_channel_type_registered_by_another_connector_does_not_count(): void
    {
        $this->app->make(ConnectorChannels::class)->add(
            new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class),
            'someone-else',
        );
        $this->vendor->package(FakeVendor::connectorComposer())->write();

        $this->artisan('platform:update')
            ->expectsOutputToContain('"echo" declares channel type "echo" but does not register it')
            ->assertExitCode(1);
    }

    public function test_solutions_cache_records_the_connectors_too(): void
    {
        $this->vendor->package(FakeVendor::connectorComposer())->write();

        $this->artisan('solutions:cache')->assertExitCode(0);

        $this->assertSame('echo', $this->cache->connectors()[0]->id ?? null);
    }

    public function test_a_declared_action_that_is_not_registered_fails_the_update_before_migrations_and_cache(): void
    {
        $this->registerFeedbackActions(score: false);
        $this->vendor->package(FakeVendor::solutionComposer())->write();

        $this->artisan('platform:update')
            ->expectsOutputToContain('acme/fapost-feedback: extra.fapost.actions[1]: "feedback.score" is declared but not registered')
            ->expectsOutputToContain('Nothing was changed')
            ->assertExitCode(1);

        $this->assertSame(0, $this->migrator->landlordRuns);
        $this->assertFileDoesNotExist($this->cache->path());
    }

    public function test_a_declared_node_type_that_is_not_registered_fails_the_update_before_migrations_and_cache(): void
    {
        $this->registerFeedbackActions(node: false);
        $this->vendor->package(FakeVendor::solutionComposer())->write();

        $this->artisan('platform:update')
            ->expectsOutputToContain('acme/fapost-feedback: extra.fapost.nodes[0]: "feedback.collect" is declared but not registered')
            ->expectsOutputToContain('Nothing was changed')
            ->assertExitCode(1);

        $this->assertSame(0, $this->migrator->landlordRuns);
        $this->assertFileDoesNotExist($this->cache->path());
    }

    public function test_a_declared_command_action_type_that_is_not_registered_fails_validation(): void
    {
        $this->registerFeedbackActions(commandType: false);
        $this->vendor->package(FakeVendor::solutionComposer())->write();

        $this->artisan('platform:update', ['--validate-only' => true])
            ->expectsOutputToContain('acme/fapost-feedback: extra.fapost.command_types[0]: "feedback.thank" is declared but not registered')
            ->expectsOutputToContain('acme/fapost-feedback: extra.fapost.commands[0]: "/feedback" takes the action type "feedback.thank", which the Solution does not register')
            ->assertExitCode(1);
    }

    public function test_a_declared_command_that_is_not_registered_fails_the_update_before_migrations_and_cache(): void
    {
        $this->registerFeedbackActions(command: false);
        $this->vendor->package(FakeVendor::solutionComposer())->write();

        $this->artisan('platform:update')
            ->expectsOutputToContain('acme/fapost-feedback: extra.fapost.commands[0]: "/feedback" is declared but not registered')
            ->expectsOutputToContain('Nothing was changed')
            ->assertExitCode(1);

        $this->assertSame(0, $this->migrator->landlordRuns);
        $this->assertFileDoesNotExist($this->cache->path());
    }

    public function test_a_command_whose_config_its_own_action_type_refuses_fails_validation(): void
    {
        $this->registerFeedbackActions(commandClass: EmptyReplyFeedbackCommand::class);
        $this->vendor->package(FakeVendor::solutionComposer())->write();

        $this->artisan('platform:update', ['--validate-only' => true])
            ->expectsOutputToContain('acme/fapost-feedback: extra.fapost.commands[0]: "/feedback": The reply text is required.')
            ->assertExitCode(1);
    }

    public function test_declared_and_registered_commands_pass_validation(): void
    {
        $this->registerFeedbackActions();
        $this->vendor->package(FakeVendor::solutionComposer())->write();

        $this->artisan('platform:update', ['--validate-only' => true])->assertExitCode(0);
    }

    public function test_validate_only_also_fails_on_a_declared_action_that_is_not_registered(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer())->write();

        $this->artisan('platform:update', ['--validate-only' => true])
            ->expectsOutputToContain('"feedback.submit" is declared but not registered')
            ->assertExitCode(1);
    }

    public function test_an_invalid_manifest_stops_the_update_before_migrations_and_cache(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(['provider' => 'Acme\\Missing']))->write();

        $this->artisan('platform:update')
            ->expectsOutputToContain('extra.fapost.provider')
            ->expectsOutputToContain('Nothing was changed')
            ->assertExitCode(1);

        $this->assertSame(0, $this->migrator->landlordRuns);
        $this->assertFileDoesNotExist($this->cache->path());
    }

    public function test_a_valid_update_migrates_and_then_caches_the_catalog(): void
    {
        $this->registerFeedbackActions();
        $this->vendor->package(FakeVendor::solutionComposer())->write();

        $this->artisan('platform:update')
            ->expectsOutputToContain('Solution catalog cached')
            ->expectsOutputToContain('horizon:terminate')
            ->assertExitCode(0);

        $this->assertSame(1, $this->migrator->landlordRuns);
        $this->assertSame('feedback', $this->cache->read()[0]->id ?? null);
    }

    public function test_a_failed_landlord_migration_stops_the_update_in_its_phase_and_leaves_no_cache(): void
    {
        $this->registerFeedbackActions();
        $this->vendor->package(FakeVendor::solutionComposer())->write();
        $this->migrator->landlordSucceeds = false;

        $this->artisan('platform:update')
            ->expectsOutputToContain('Phase 3 (migrate platform): Landlord migrations failed. Nothing after them ran')
            ->doesntExpectOutputToContain('Phase 4 of 5')
            ->assertExitCode(1);

        $this->assertSame([], $this->migrator->solutionRuns);
        $this->assertFileDoesNotExist($this->cache->path());
    }

    public function test_a_failed_tenant_still_lets_the_solutions_migrate_but_skips_activation(): void
    {
        $this->registerFeedbackActions();
        $this->installFeedbackWithMigrations();
        $this->migrator->tenantsSucceed = false;

        $this->artisan('platform:update')
            ->expectsOutputToContain('Phase 3 (migrate platform): tenant migrations failed for at least one tenant')
            ->expectsOutputToContain('Phase 3 (migrate platform): The update stopped before activation')
            ->doesntExpectOutputToContain('Phase 5 of 5')
            ->assertExitCode(1);

        $this->assertSame([['feedback']], $this->migrator->solutionRuns);
        $this->assertFileDoesNotExist($this->cache->path());
    }

    public function test_solution_failures_after_a_failed_tenant_say_the_tenant_may_be_the_cause(): void
    {
        $this->registerFeedbackActions();
        $this->installFeedbackWithMigrations();
        $this->migrator->tenantsSucceed   = false;
        $this->migrator->failingSolutions = ['feedback'];

        $this->artisan('platform:update')
            ->expectsOutputToContain('Phase 4 (migrate Solutions): Solution "feedback" failed to migrate in at least one tenant')
            ->expectsOutputToContain('A tenant that failed in phase 3 fails every Solution')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->cache->path());
    }

    public function test_a_failed_solution_is_named_and_the_update_still_activates_everything(): void
    {
        $this->registerFeedbackActions();
        $this->installFeedbackWithMigrations();
        $this->migrator->failingSolutions = ['feedback'];

        $this->artisan('platform:update')
            ->expectsOutputToContain('Phase 4 (migrate Solutions): Solution "feedback" failed to migrate')
            ->expectsOutputToContain('Phase 5 of 5: activate')
            ->expectsOutputToContain('Solution catalog cached')
            ->expectsOutputToContain('Phase 4 (migrate Solutions): The update finished for everything else, but 1 Solution(s) failed to migrate: feedback.')
            ->assertExitCode(1);

        $this->assertSame('feedback', $this->cache->read()[0]->id ?? null);
    }

    public function test_only_solutions_that_ship_migrations_are_migrated(): void
    {
        $this->registerFeedbackActions();
        $this->vendor->package(FakeVendor::solutionComposer())->write();

        $this->artisan('platform:update')
            ->expectsOutputToContain('Phase 4 of 5: migrate Solutions')
            ->assertExitCode(0);

        $this->assertSame([[]], $this->migrator->solutionRuns);
    }

    public function test_a_validation_failure_names_its_phase(): void
    {
        $this->registerFeedbackActions(score: false);
        $this->vendor->package(FakeVendor::solutionComposer())->write();

        $this->artisan('platform:update')
            ->expectsOutputToContain('Phase 2 (boot validation): 1 declared action(s), node type(s) and command(s) are not registered or not valid. Nothing was changed.')
            ->doesntExpectOutputToContain('Phase 3 of 5')
            ->assertExitCode(1);

        $this->assertSame(0, $this->migrator->landlordRuns);
    }

    public function test_an_install_without_solutions_updates_cleanly(): void
    {
        $this->vendor->write();

        $this->artisan('platform:update')->assertExitCode(0);

        $this->assertSame([], $this->cache->read());
    }

    public function test_solutions_cache_and_clear_manage_the_file(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer())->write();

        $this->artisan('solutions:cache')->assertExitCode(0);
        $this->assertFileExists($this->cache->path());

        $this->artisan('solutions:clear')->assertExitCode(0);
        $this->assertFileDoesNotExist($this->cache->path());
    }

    public function test_solutions_cache_refuses_to_cache_a_broken_catalog(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(['name' => null]))->write();

        $this->artisan('solutions:cache')
            ->expectsOutputToContain('extra.fapost.name: required')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->cache->path());
    }

    public function test_a_migration_path_a_solution_registered_with_the_landlord_migrator_refuses_the_update_before_any_migration(): void
    {
        $this->registerFeedbackActions();
        $this->vendor->package(FakeVendor::solutionComposer())->write();
        $path = $this->registerWithLandlordMigrator('acme/fapost-feedback', 'database/migrations');

        $this->artisan('platform:update')
            ->expectsOutputToContain('1 extension migration path(s) were registered with the landlord migrator. Nothing was changed.')
            ->expectsOutputToContain('acme/fapost-feedback: "' . $path . '" is registered with loadMigrationsFrom()')
            ->assertExitCode(1);

        $this->assertSame(0, $this->migrator->landlordRuns, 'The migrator never ran.');
        $this->assertFileDoesNotExist($this->cache->path());
    }

    public function test_the_refusal_holds_for_validate_only_too(): void
    {
        $this->registerFeedbackActions();
        $this->vendor->package(FakeVendor::solutionComposer())->write();
        $this->registerWithLandlordMigrator('acme/fapost-feedback', 'database');

        $this->artisan('platform:update', ['--validate-only' => true])
            ->expectsOutputToContain('extension migration path(s) were registered with the landlord migrator')
            ->assertExitCode(1);
    }

    public function test_a_migration_path_a_connector_registered_with_the_landlord_migrator_is_refused_too(): void
    {
        $this->registerEchoChannel();
        $this->vendor->package(FakeVendor::connectorComposer())->write();
        $this->registerWithLandlordMigrator('acme/fapost-connector-echo', 'database/migrations');

        $this->artisan('platform:update')
            ->expectsOutputToContain('acme/fapost-connector-echo: ')
            ->assertExitCode(1);

        $this->assertSame(0, $this->migrator->landlordRuns);
    }

    public function test_every_registered_path_inside_a_package_is_listed(): void
    {
        $this->registerFeedbackActions();
        $this->vendor->package(FakeVendor::solutionComposer())->write();
        $this->registerWithLandlordMigrator('acme/fapost-feedback', 'database/migrations');
        $this->registerWithLandlordMigrator('acme/fapost-feedback', 'other');

        $this->artisan('platform:update')
            ->expectsOutputToContain('2 extension migration path(s) were registered with the landlord migrator')
            ->assertExitCode(1);
    }

    public function test_a_path_registered_outside_every_package_and_one_that_does_not_exist_do_not_refuse_the_update(): void
    {
        $this->registerFeedbackActions();
        $this->vendor->package(FakeVendor::solutionComposer())->write();
        $outside = dirname($this->vendor->path) . '/elsewhere';
        mkdir($outside);
        $this->app->make('migrator')->path($outside);
        $this->app->make('migrator')->path($this->vendor->path . '/acme/fapost-feedback/never-created');

        $this->artisan('platform:update')->assertExitCode(0);

        $this->assertSame(1, $this->migrator->landlordRuns);
    }

    public function test_a_sibling_directory_whose_name_starts_like_a_package_is_not_inside_it(): void
    {
        $this->registerFeedbackActions();
        $this->vendor->package(FakeVendor::solutionComposer())->write();
        $sibling = $this->vendor->path . '/acme/fapost-feedback-extras';
        mkdir($sibling, 0o777, true);
        $this->app->make('migrator')->path($sibling);

        $this->artisan('platform:update')->assertExitCode(0);
    }

    /**
     * The fixture Solution with one tenant migration in its package, so its manifest carries a migrations digest.
     */
    private function installFeedbackWithMigrations(): void
    {
        $this->vendor->package(FakeVendor::solutionComposer(['migrations' => 'database/migrations']))->write();

        $directory = $this->vendor->path . '/acme/fapost-feedback/database/migrations';
        mkdir($directory, 0o777, true);
        file_put_contents($directory . '/2026_11_27_000001_create_feedback_entries_table.php', "<?php\n");
    }

    /**
     * Registers a directory of the installed package with Laravel's migrator, as `loadMigrationsFrom()` in a provider does.
     */
    private function registerWithLandlordMigrator(string $package, string $directory): string
    {
        $path = $this->vendor->path . '/' . $package . '/' . $directory;

        if (!is_dir($path)) {
            mkdir($path, 0o777, true);
        }

        $this->app->make('migrator')->path($path);

        return $path;
    }

    /**
     * What the booted application holds once the fixture Solution has registered its handlers.
     */
    private function registerEchoChannel(): void
    {
        $this->app->make(ConnectorChannels::class)->add(
            new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class),
            'echo',
        );
    }

    private function registerFeedbackActions(bool $score = true, bool $node = true, bool $commandType = true, bool $command = true, string $commandClass = FeedbackCommand::class): void
    {
        if ($node) {
            $this->app->make(NodeHandlerRegistryInterface::class)->register(FeedbackCollectNode::class, 'feedback');
        }

        $registry = $this->app->make(ActionHandlerRegistry::class);
        $registry->register(FeedbackSubmitAction::class, 'feedback');

        if ($score) {
            $registry->register(FeedbackScoreAction::class, 'feedback');
        }

        if ($commandType) {
            $this->app->make(CommandActionTypeRegistry::class)->register(FeedbackThankCommandType::class, 'feedback');
        }

        if ($command) {
            $this->app->make(SolutionCommandRegistry::class)->register($commandClass, 'feedback');
        }
    }
}

/**
 * The fixture's `/feedback` command with the config its own `feedback.thank` type refuses.
 */
final class EmptyReplyFeedbackCommand implements SolutionCommandInterface
{
    public function command(): string
    {
        return FeedbackCommand::COMMAND;
    }

    public function actionType(): string
    {
        return FeedbackThankCommandType::TYPE;
    }

    public function config(): array
    {
        return [];
    }

    public function label(): string
    {
        return 'Leave feedback';
    }
}
