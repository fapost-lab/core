<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Enums\SolutionActivationStatus;
use App\Domains\Solution\Services\SolutionSchemas;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Fapost\Foundation\Solution\Contracts\SolutionEntitlementInterface;
use Fapost\Foundation\Solution\DTO\SolutionEntitlement;
use Fapost\Foundation\Solution\DTO\SolutionGrant;
use Fapost\Foundation\Solution\Enums\SolutionGrantSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\PendingCommand;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeSolutionEntitlement;
use Tests\Support\InstallsMigratingSolutions;

/**
 * `solutions:migrate` against the seeded tenant `main`: with `--tenant` it prepares what the tenant runs, without it
 * (what `platform:update` does) it only keeps current what already has tables.
 */
final class MigrateSolutionsCommandTest extends FeatureTestCase
{
    use InstallsMigratingSolutions;

    private const string CREATE_ITEMS = '2026_11_16_000001_create_items_table';

    private const string SEED_ITEMS = '2026_11_16_000002_seed_items';

    protected function setUp(): void
    {
        parent::setUp();

        $this->installMigratingSolutions([
            'alpha' => [self::CREATE_ITEMS => self::migrationCreating('alpha_items')],
            'beta'  => [self::CREATE_ITEMS => self::migrationCreating('beta_items')],
            'gamma' => [self::CREATE_ITEMS => self::migrationCreating('gamma_items')],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeMigratingSolutions();

        parent::tearDown();
    }

    public function test_the_tenant_option_prepares_an_active_solution_that_has_no_tables_yet(): void
    {
        $this->storeActive('alpha');

        $this->migrate(['--tenant' => 'main'])
            ->expectsOutputToContain('✔ main alpha: ' . self::CREATE_ITEMS)
            ->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('alpha_items'));
        $this->assertFalse(Schema::hasTable('beta_items'), 'Installed but not active here.');
        $this->assertSame(['alpha' => self::CREATE_ITEMS], $this->footprints());
    }

    public function test_the_tenant_option_prepares_a_granted_solution(): void
    {
        $operator          = new FakeSolutionEntitlement();
        $operator->default = SolutionEntitlement::grants([new SolutionGrant('beta', SolutionGrantSource::Plan)]);
        $this->app->instance(SolutionEntitlementInterface::class, $operator);

        $this->migrate(['--tenant' => 'main'])->expectsOutputToContain('✔ main beta')->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('beta_items'));
        $this->assertSame(0, DB::table('solution_activations')->count(), 'Nothing stored for a grant.');
    }

    public function test_the_tenant_option_also_keeps_current_a_deactivated_solution_that_has_tables(): void
    {
        $this->migrateSchema('beta');
        $this->deactivateInTenant('beta');
        $this->upgrade('beta', self::SEED_ITEMS, self::migrationInserting('beta_items'));

        $this->migrate(['--tenant' => 'main'])
            ->expectsOutputToContain('✔ main beta: ' . self::SEED_ITEMS)
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('beta_items')->count());
    }

    public function test_without_the_tenant_option_solutions_with_tables_and_stored_active_ones_are_kept_current_and_the_rest_are_not(): void
    {
        $this->storeActive('alpha');
        $this->migrateSchema('beta');
        $this->upgrade('beta', self::SEED_ITEMS, self::migrationInserting('beta_items'));

        $this->migrate()
            ->expectsOutputToContain('✔ main beta: ' . self::SEED_ITEMS)
            ->expectsOutputToContain('✔ main alpha: ' . self::CREATE_ITEMS)
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('beta_items')->count());
        $this->assertTrue(Schema::hasTable('alpha_items'), 'Stored active but never migrated (turned on before it had migrations): prepared by the upgrade.');
        $this->assertFalse(Schema::hasTable('gamma_items'), 'Installed only: untouched.');
        $this->assertSame(['alpha' => self::CREATE_ITEMS, 'beta' => self::SEED_ITEMS], $this->footprints());
    }

    public function test_without_the_tenant_option_a_stored_inactive_solution_without_tables_is_not_prepared(): void
    {
        $this->storeActive('alpha');
        $this->deactivateInTenant('alpha');

        $this->migrate()->doesntExpectOutputToContain('✔')->assertExitCode(0);

        $this->assertFalse(Schema::hasTable('alpha_items'));
    }

    public function test_a_file_dated_before_an_applied_one_that_arrives_in_an_upgrade_is_applied(): void
    {
        $this->installMigratingSolutions(['late' => [self::SEED_ITEMS => self::migrationCreating('late_second')]]);
        $this->migrateSchema('late');

        $this->upgrade('late', self::CREATE_ITEMS, self::migrationCreating('late_first'));

        $this->migrate()->expectsOutputToContain('✔ main late: ' . self::CREATE_ITEMS)->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('late_first'));
        $this->assertSame(['late' => self::SEED_ITEMS], $this->footprints());
        $this->migrate()->doesntExpectOutputToContain('✔')->assertExitCode(0);
    }

    public function test_without_the_tenant_option_a_granted_solution_is_not_prepared_and_the_operator_is_not_asked(): void
    {
        $operator          = new FakeSolutionEntitlement();
        $operator->default = SolutionEntitlement::grants([new SolutionGrant('alpha', SolutionGrantSource::Addon)]);
        $this->app->instance(SolutionEntitlementInterface::class, $operator);
        $this->migrateSchema('beta');

        $this->migrate()->assertExitCode(0);

        $this->assertFalse(Schema::hasTable('alpha_items'));
        $this->assertSame([], $operator->asked);
    }

    public function test_a_run_with_nothing_to_do_prints_nothing_and_succeeds(): void
    {
        $this->migrateSchema('beta');

        $this->migrate()->doesntExpectOutputToContain('✔')->doesntExpectOutputToContain('✘')->assertExitCode(0);
        $this->migrate(['--tenant' => 'main'])->doesntExpectOutputToContain('✔')->assertExitCode(0);
    }

    public function test_the_solution_option_narrows_the_run_to_one_solution(): void
    {
        $this->migrateSchema('beta');
        $this->migrateSchema('gamma');
        $this->upgrade('beta', self::SEED_ITEMS, self::migrationInserting('beta_items'));
        $this->upgrade('gamma', self::SEED_ITEMS, self::migrationInserting('gamma_items'));

        $this->migrate(['--solution' => 'gamma'])->expectsOutputToContain('✔ main gamma')->assertExitCode(0);

        $this->assertSame(0, DB::table('beta_items')->count(), 'beta waits.');
        $this->assertSame(1, DB::table('gamma_items')->count());
    }

    public function test_the_solution_option_with_a_tenant_refuses_a_solution_that_is_neither_on_for_the_tenant_nor_present_in_its_schema(): void
    {
        $this->migrate(['--tenant' => 'main', '--solution' => 'gamma'])
            ->expectsOutputToContain('✘ main gamma: the Solution is neither on for this tenant nor present in its schema')
            ->assertExitCode(1);

        $this->assertFalse(Schema::hasTable('gamma_items'));
    }

    public function test_the_solution_option_with_a_tenant_accepts_a_stored_active_a_present_and_a_granted_solution(): void
    {
        $this->storeActive('alpha');
        $this->migrateSchema('beta');
        $this->deactivateInTenant('beta');
        $this->upgrade('beta', self::SEED_ITEMS, self::migrationInserting('beta_items'));
        $operator          = new FakeSolutionEntitlement();
        $operator->default = SolutionEntitlement::grants([new SolutionGrant('gamma', SolutionGrantSource::Addon)]);
        $this->app->instance(SolutionEntitlementInterface::class, $operator);

        $this->migrate(['--tenant' => 'main', '--solution' => 'alpha'])->expectsOutputToContain('✔ main alpha')->assertExitCode(0);
        $this->migrate(['--tenant' => 'main', '--solution' => 'beta'])->expectsOutputToContain('✔ main beta: ' . self::SEED_ITEMS)->assertExitCode(0);
        $this->migrate(['--tenant' => 'main', '--solution' => 'gamma'])->expectsOutputToContain('✔ main gamma')->assertExitCode(0);
    }

    public function test_a_solution_that_is_not_installed_is_reported_and_fails_the_run(): void
    {
        $this->migrate(['--tenant' => 'main', '--solution' => 'ghost'])
            ->expectsOutputToContain('✘ main ghost')
            ->assertExitCode(1);
    }

    public function test_a_failing_solution_is_reported_and_fails_the_run_without_stopping_the_others(): void
    {
        $this->migrateSchema('beta');
        $this->migrateSchema('gamma');
        $this->upgrade('beta', self::SEED_ITEMS, self::migrationThrowing('beta is broken'));
        $this->upgrade('gamma', self::SEED_ITEMS, self::migrationInserting('gamma_items'));

        $this->migrate()
            ->expectsOutputToContain('✘ main beta: Solution "beta": migration "' . self::SEED_ITEMS . '" failed: beta is broken')
            ->expectsOutputToContain('✔ main gamma: ' . self::SEED_ITEMS)
            ->assertExitCode(1);

        $this->assertSame(['beta' => self::CREATE_ITEMS, 'gamma' => self::SEED_ITEMS], $this->footprints());
    }

    public function test_a_failure_with_the_tenant_option_fails_the_run_and_leaves_the_solution_not_ready(): void
    {
        $this->storeActive('alpha');
        $this->upgrade('alpha', self::CREATE_ITEMS, self::migrationThrowing('alpha is broken'));

        $this->migrate(['--tenant' => 'main'])->expectsOutputToContain('✘ main alpha')->assertExitCode(1);

        $this->assertSame([], $this->footprints());
    }

    public function test_an_unknown_tenant_fails(): void
    {
        $this->migrate(['--tenant' => 'nobody'])
            ->expectsOutputToContain('No active tenant with the slug "nobody"')
            ->assertExitCode(1);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function migrate(array $options = []): PendingCommand
    {
        $this->app->forgetScopedInstances();

        $command = $this->artisan('solutions:migrate', $options);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function upgrade(string $id, string $name, string $source): void
    {
        $this->upgradeMigratingSolution($id, $name, $source);
    }

    private function storeActive(string $id): void
    {
        $this->inMainTenant(fn () => $this->app->make(SolutionActivationStoreInterface::class)->change($id, SolutionActivationStatus::Active, ActivationActor::console(), null));
    }

    private function deactivateInTenant(string $id): void
    {
        $this->inMainTenant(fn () => $this->app->make(SolutionActivationStoreInterface::class)->change($id, SolutionActivationStatus::Inactive, ActivationActor::console(), null));
    }

    private function migrateSchema(string $id): void
    {
        $this->inMainTenant(fn () => $this->app->make(SolutionSchemas::class)->bringCurrent($id));
    }

    private function inMainTenant(callable $callback): mixed
    {
        $tenant = $this->app->make(TenantRepositoryInterface::class)->findBySlug('main');
        $this->assertNotNull($tenant);

        $result = $this->app->make(TenantSwitcher::class)->runForTenant($tenant, $callback(...));
        $this->app->forgetScopedInstances();

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function footprints(): array
    {
        /** @var array<string, string> $latest */
        $latest = DB::table('solution_schemas')->orderBy('solution_id')->pluck('latest_migration', 'solution_id')->all();

        return $latest;
    }
}
