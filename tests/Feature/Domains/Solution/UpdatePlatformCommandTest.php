<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Console\Platform\PlatformMigratorInterface;
use App\Domains\Channels\ConnectorChannels;
use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Solution\Services\InstalledPackagesReader;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use App\Domains\Solution\Services\SolutionManifestValidator;
use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\Solution\Manifest\ManifestParser;
use Symfony\Component\Console\Output\OutputInterface;
use Tests\Fixtures\Connectors\Echo\EchoInboundAdapter;
use Tests\Fixtures\Connectors\Echo\EchoSender;
use Tests\Fixtures\Solution\Actions\FeedbackScoreAction;
use Tests\Fixtures\Solution\Actions\FeedbackSubmitAction;
use Tests\Support\FakeVendor;
use Tests\TestCase;

/**
 * Deliberately not a database test: `--validate-only` must run where there is none.
 */
final class UpdatePlatformCommandTest extends TestCase
{
    private FakeVendor $vendor;

    private SolutionCache $cache;

    private int $migrations = 0;

    private bool $migrationsSucceed = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor            = new FakeVendor();
        $this->cache             = new SolutionCache(dirname($this->vendor->path) . '/fapost-solutions.php');
        $this->migrations        = 0;
        $this->migrationsSucceed = true;

        $this->app->instance(SolutionCache::class, $this->cache);
        $this->app->instance(SolutionDiscoverer::class, new SolutionDiscoverer(
            new InstalledPackagesReader($this->vendor->path),
            new SolutionManifestValidator(new ManifestParser()),
        ));
        $this->app->instance(PlatformMigratorInterface::class, new class ($this) implements PlatformMigratorInterface {
            public function __construct(private readonly UpdatePlatformCommandTest $test)
            {
            }

            public function migrate(OutputInterface $output): bool
            {
                return $this->test->recordMigration();
            }
        });
    }

    protected function tearDown(): void
    {
        $this->vendor->cleanup();

        parent::tearDown();
    }

    public function recordMigration(): bool
    {
        $this->migrations++;

        return $this->migrationsSucceed;
    }

    public function test_validate_only_passes_on_a_valid_manifest_and_touches_nothing(): void
    {
        $this->registerFeedbackActions();
        $this->vendor->package(FakeVendor::solutionComposer(), '1.2.0')->write();

        $this->artisan('platform:update', ['--validate-only' => true])
            ->expectsOutputToContain('Solution manifests are valid (1 installed)')
            ->expectsOutputToContain('acme/fapost-feedback')
            ->assertExitCode(0);

        $this->assertSame(0, $this->migrations);
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

        $this->assertSame(0, $this->migrations);
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

        $this->assertSame(0, $this->migrations);
        $this->assertFileDoesNotExist($this->cache->path());
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

        $this->assertSame(0, $this->migrations);
        $this->assertFileDoesNotExist($this->cache->path());
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

        $this->assertSame(0, $this->migrations);
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

        $this->assertSame(1, $this->migrations);
        $this->assertSame('feedback', $this->cache->read()[0]->id ?? null);
    }

    public function test_a_failed_migration_fails_the_update_and_leaves_no_cache(): void
    {
        $this->registerFeedbackActions();
        $this->vendor->package(FakeVendor::solutionComposer())->write();
        $this->migrationsSucceed = false;

        $this->artisan('platform:update')
            ->expectsOutputToContain('Migrations failed')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($this->cache->path());
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

    private function registerFeedbackActions(bool $score = true): void
    {
        $registry = $this->app->make(ActionHandlerRegistry::class);
        $registry->register(FeedbackSubmitAction::class, 'feedback');

        if ($score) {
            $registry->register(FeedbackScoreAction::class, 'feedback');
        }
    }
}
