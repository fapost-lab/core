<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Enums\ActivationChange;
use App\Domains\Solution\Exceptions\SolutionMigrationFailedException;
use App\Domains\Solution\Exceptions\SolutionNotEntitledException;
use App\Domains\Solution\Infrastructure\SolutionActivationEventRecord;
use App\Domains\Solution\Infrastructure\SolutionActivationRecord;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Solution\Contracts\SolutionEntitlementInterface;
use Fapost\Foundation\Solution\DTO\SolutionEntitlement;
use Fapost\Foundation\Solution\DTO\SolutionGrant;
use Fapost\Foundation\Solution\Enums\SolutionGrantSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeSolutionEntitlement;
use Tests\Support\InstallsMigratingSolutions;

/**
 * Activation of a Solution that keeps tables: the migrations run before the row is stored, and a failure refuses the
 * activation with nothing stored.
 */
final class SolutionActivatorMigrationsTest extends FeatureTestCase
{
    use InstallsMigratingSolutions;

    private const string CREATE_NOTES = '2026_11_16_000001_create_notes_table';

    protected function tearDown(): void
    {
        $this->removeMigratingSolutions();

        parent::tearDown();
    }

    public function test_activating_a_solution_creates_its_tables_and_then_stores_it_as_active(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);

        $this->inTenant(function (): void {
            $change = $this->app->make(SolutionActivator::class)->activate('notes', ActivationActor::console());

            $this->assertSame(ActivationChange::Activated, $change);
            $this->assertTrue($this->app->make(ActiveSolutions::class)->isActive('notes'));
            $this->assertTrue($this->app->make(ActiveSolutions::class)->isReady('notes'));
        });

        $this->assertTrue(Schema::hasTable('notes_items'));
        $this->assertSame(self::CREATE_NOTES, DB::table('solution_schemas')->where('solution_id', 'notes')->value('latest_migration'));
        $this->assertSame(1, SolutionActivationRecord::query()->where('solution_id', 'notes')->count());
    }

    public function test_a_failing_migration_refuses_the_activation_and_stores_nothing(): void
    {
        $this->installMigratingSolutions(['notes' => [
            self::CREATE_NOTES         => self::migrationCreating('notes_items'),
            '2026_11_16_000002_breaks' => self::migrationThrowing(),
        ]]);

        $this->inTenant(function (): void {
            try {
                $this->app->make(SolutionActivator::class)->activate('notes', ActivationActor::console());
                $this->fail('Expected SolutionMigrationFailedException.');
            } catch (SolutionMigrationFailedException $exception) {
                $this->assertStringContainsString('2026_11_16_000002_breaks', $exception->getMessage());
            }

            $this->assertFalse($this->app->make(ActiveSolutions::class)->isActive('notes'));
        });

        $this->assertSame(0, SolutionActivationRecord::query()->count());
        $this->assertSame(0, SolutionActivationEventRecord::query()->count());
        $this->assertSame(0, DB::table('solution_schemas')->count());
        $this->assertFalse(Schema::hasTable('notes_items'));
    }

    public function test_a_refused_activation_succeeds_once_the_package_is_fixed(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationThrowing()]]);
        $this->inTenant(function (): void {
            try {
                $this->app->make(SolutionActivator::class)->activate('notes', ActivationActor::console());
                $this->fail('Expected SolutionMigrationFailedException.');
            } catch (SolutionMigrationFailedException) {
                // expected
            }
        });

        $this->upgradeMigratingSolution('notes', self::CREATE_NOTES, self::migrationCreating('notes_items'));

        $this->inTenant(fn () => $this->assertSame(ActivationChange::Activated, $this->app->make(SolutionActivator::class)->activate('notes', ActivationActor::console())));
        $this->assertTrue(Schema::hasTable('notes_items'));
    }

    public function test_a_solution_the_tenant_is_not_entitled_to_is_refused_before_anything_is_migrated(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);
        $operator          = new FakeSolutionEntitlement();
        $operator->default = SolutionEntitlement::only([]);
        $this->app->instance(SolutionEntitlementInterface::class, $operator);

        $this->inTenant(function (): void {
            try {
                $this->app->make(SolutionActivator::class)->activate('notes', ActivationActor::console());
                $this->fail('Expected SolutionNotEntitledException.');
            } catch (SolutionNotEntitledException) {
                // expected
            }
        });

        $this->assertFalse(Schema::hasTable('notes_items'));
        $this->assertSame(0, DB::table('solution_schemas')->count());
    }

    public function test_activating_again_after_a_deactivation_applies_only_what_the_upgrade_added(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);
        $this->inTenant(function (): void {
            $activator = $this->app->make(SolutionActivator::class);
            $activator->activate('notes', ActivationActor::console());
            $activator->deactivate('notes', ActivationActor::console());
        });
        DB::table('notes_items')->insert(['id' => (string) Str::uuid(), 'title' => 'survives deactivation']);

        $this->upgradeMigratingSolution('notes', '2026_11_16_000002_seed_notes', self::migrationInserting('notes_items'));
        $this->app->forgetScopedInstances();

        $this->inTenant(function (): void {
            $this->assertSame(ActivationChange::Activated, $this->app->make(SolutionActivator::class)->activate('notes', ActivationActor::console()));
        });

        $this->assertSame(2, DB::table('notes_items')->count(), 'The kept row, plus the one the new migration seeded.');
        $this->assertSame('2026_11_16_000002_seed_notes', DB::table('solution_schemas')->where('solution_id', 'notes')->value('latest_migration'));
    }

    public function test_a_granted_solution_is_prepared_on_activation_without_storing_a_row(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationCreating('notes_items')]]);
        $operator          = new FakeSolutionEntitlement();
        $operator->default = SolutionEntitlement::grants([new SolutionGrant('notes', SolutionGrantSource::Plan)]);
        $this->app->instance(SolutionEntitlementInterface::class, $operator);

        $this->inTenant(function (): void {
            $active = $this->app->make(ActiveSolutions::class);
            $this->assertTrue($active->isActive('notes'), 'The grant alone runs it.');
            $this->assertFalse($active->isReady('notes'), 'But its tables are not there.');

            $change = $this->app->make(SolutionActivator::class)->activate('notes', ActivationActor::console());

            $this->assertSame(ActivationChange::Unchanged, $change);
            $this->assertTrue($this->app->make(ActiveSolutions::class)->isReady('notes'));
        });

        $this->assertTrue(Schema::hasTable('notes_items'));
        $this->assertSame(0, SolutionActivationRecord::query()->count());
        $this->assertSame(0, SolutionActivationEventRecord::query()->count());
    }

    public function test_a_granted_solution_whose_migration_fails_stays_not_ready_and_the_activation_is_refused(): void
    {
        $this->installMigratingSolutions(['notes' => [self::CREATE_NOTES => self::migrationThrowing()]]);
        $operator          = new FakeSolutionEntitlement();
        $operator->default = SolutionEntitlement::grants([new SolutionGrant('notes', SolutionGrantSource::Addon)]);
        $this->app->instance(SolutionEntitlementInterface::class, $operator);

        $this->inTenant(function (): void {
            try {
                $this->app->make(SolutionActivator::class)->activate('notes', ActivationActor::console());
                $this->fail('Expected SolutionMigrationFailedException.');
            } catch (SolutionMigrationFailedException) {
                // expected
            }

            $this->assertTrue($this->app->make(ActiveSolutions::class)->isActive('notes'));
            $this->assertFalse($this->app->make(ActiveSolutions::class)->isReady('notes'));
        });
    }

    private function inTenant(callable $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant(new RuntimeTenant('tenant-one', 'main', 'one'), $callback(...));
    }
}
