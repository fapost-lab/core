<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Enums\ActivationAction;
use App\Domains\Solution\Enums\ActivationActorKind;
use App\Domains\Solution\Enums\ActivationChange;
use App\Domains\Solution\Enums\SolutionActivationStatus;
use App\Domains\Solution\Exceptions\SolutionNotInstalledException;
use App\Domains\Solution\Infrastructure\SolutionActivationEventRecord;
use App\Domains\Solution\Infrastructure\SolutionActivationRecord;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use LogicException;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\InstallsFakeSolutions;

/**
 * Activation against the real tenant tables (one schema here; two real ones in the Postgres test).
 */
final class SolutionActivatorTest extends FeatureTestCase
{
    use InstallsFakeSolutions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installSolutions('feedback', 'survey');
    }

    protected function tearDown(): void
    {
        $this->removeFakeSolutions();

        parent::tearDown();
    }

    public function test_a_tenant_starts_with_nothing_active_and_a_read_writes_nothing(): void
    {
        $this->inTenant(function (): void {
            $this->assertSame([], $this->app->make(ActiveSolutions::class)->ids());
        });

        $this->assertSame(0, SolutionActivationRecord::query()->count());
        $this->assertSame(0, SolutionActivationEventRecord::query()->count());
    }

    public function test_activating_an_installed_solution_stores_it_and_journals_who_did_it(): void
    {
        $this->inTenant(function (): void {
            $change = $this->app->make(SolutionActivator::class)->activate('feedback', ActivationActor::user('0198a1b2-0000-7000-8000-000000000001', 'Ann Admin'));

            $this->assertSame(ActivationChange::Activated, $change);
            $this->assertTrue($this->app->make(ActiveSolutions::class)->isActive('feedback'));
        });

        $row = SolutionActivationRecord::query()->where('solution_id', 'feedback')->firstOrFail();
        $this->assertSame(SolutionActivationStatus::Active, $row->status);
        $this->assertNull($row->deactivated_at);

        $event = SolutionActivationEventRecord::query()->firstOrFail();
        $this->assertSame(ActivationAction::Activated, $event->action);
        $this->assertSame(ActivationActorKind::User, $event->actor_kind);
        $this->assertSame('0198a1b2-0000-7000-8000-000000000001', $event->actor_id);
        $this->assertSame('Ann Admin', $event->actor_label);
        $this->assertNull($event->reason);
    }

    public function test_a_solution_that_is_not_installed_cannot_be_activated(): void
    {
        $this->inTenant(function (): void {
            try {
                $this->app->make(SolutionActivator::class)->activate('ghost', ActivationActor::console());
                $this->fail('Expected SolutionNotInstalledException.');
            } catch (SolutionNotInstalledException $exception) {
                $this->assertStringContainsString('ghost', $exception->getMessage());
            }
        });

        $this->assertSame(0, SolutionActivationRecord::query()->count());
        $this->assertSame(0, SolutionActivationEventRecord::query()->count());
    }

    public function test_repeating_a_change_is_idempotent_and_journals_once(): void
    {
        $this->inTenant(function (): void {
            $activator = $this->app->make(SolutionActivator::class);

            $this->assertSame(ActivationChange::Activated, $activator->activate('feedback', ActivationActor::console()));
            $this->assertSame(ActivationChange::Unchanged, $activator->activate('feedback', ActivationActor::console()));
            $this->assertSame(ActivationChange::Deactivated, $activator->deactivate('feedback', ActivationActor::console()));
            $this->assertSame(ActivationChange::Unchanged, $activator->deactivate('feedback', ActivationActor::console()));
        });

        $this->assertSame(1, SolutionActivationRecord::query()->count());
        $this->assertSame(
            ['activated', 'deactivated'],
            SolutionActivationEventRecord::query()->orderBy('occurred_at')->orderBy('id')->pluck('action')->map(static fn ($action) => $action->value)->all(),
        );
    }

    public function test_deactivating_keeps_the_row_and_records_the_reason(): void
    {
        $this->inTenant(function (): void {
            $activator = $this->app->make(SolutionActivator::class);
            $activator->activate('feedback', ActivationActor::console());
            $activator->deactivate('feedback', ActivationActor::operator('support@example.test'), 'entitlement lost');

            $this->assertFalse($this->app->make(ActiveSolutions::class)->isActive('feedback'), 'Seen at once in the same job.');
        });

        $row = SolutionActivationRecord::query()->where('solution_id', 'feedback')->firstOrFail();
        $this->assertSame(SolutionActivationStatus::Inactive, $row->status);
        $this->assertNotNull($row->deactivated_at);

        $event = SolutionActivationEventRecord::query()->where('action', 'deactivated')->firstOrFail();
        $this->assertSame(ActivationActorKind::Operator, $event->actor_kind);
        $this->assertSame('support@example.test', $event->actor_label);
        $this->assertSame('entitlement lost', $event->reason);
    }

    public function test_deactivating_a_solution_that_was_never_activated_writes_nothing(): void
    {
        $this->inTenant(function (): void {
            $this->assertSame(ActivationChange::Unchanged, $this->app->make(SolutionActivator::class)->deactivate('feedback', ActivationActor::console()));
        });

        $this->assertSame(0, SolutionActivationRecord::query()->count());
    }

    public function test_an_orphan_can_be_deactivated_and_shows_in_the_stored_list(): void
    {
        $this->inTenant(function (): void {
            $activator = $this->app->make(SolutionActivator::class);
            $activator->activate('feedback', ActivationActor::console());

            // The package is removed from the image.
            $this->installSolutions('survey');
            $store = $this->app->make(SolutionActivationStoreInterface::class);

            $this->assertSame(['feedback'], array_map(static fn ($row) => $row->solutionId, $store->all()));
            $this->assertFalse($this->app->make(ActiveSolutions::class)->isActive('feedback'));

            $this->assertSame(ActivationChange::Deactivated, $this->app->make(SolutionActivator::class)->deactivate('feedback', ActivationActor::console()));
            $this->assertSame([], $store->activeIds());
        });
    }

    public function test_activation_survives_a_worker_restart(): void
    {
        $this->inTenant(fn () => $this->app->make(SolutionActivator::class)->activate('feedback', ActivationActor::console()));

        $this->app->forgetScopedInstances();
        $this->app->forgetInstance(ActiveSolutions::class);

        $this->inTenant(function (): void {
            $this->assertSame(['feedback'], $this->app->make(ActiveSolutions::class)->ids(), 'State is in the database, not in the process.');
        });
    }

    public function test_a_deactivation_from_another_process_reaches_the_next_job(): void
    {
        $this->inTenant(fn () => $this->app->make(SolutionActivator::class)->activate('feedback', ActivationActor::console()));

        $this->inTenant(fn () => $this->assertTrue($this->app->make(ActiveSolutions::class)->isActive('feedback')));

        // Another process writes straight to the table; this process learns only at its next job.
        SolutionActivationRecord::query()->where('solution_id', 'feedback')->update(['status' => 'inactive']);
        $this->app->forgetScopedInstances();

        $this->inTenant(fn () => $this->assertFalse($this->app->make(ActiveSolutions::class)->isActive('feedback')));
    }

    public function test_a_row_never_survives_without_its_journal_entry(): void
    {
        SolutionActivationEventRecord::creating(static function (): void {
            throw new RuntimeException('journal unavailable');
        });

        $this->inTenant(function (): void {
            try {
                $this->app->make(SolutionActivator::class)->activate('feedback', ActivationActor::console());
                $this->fail('Expected the journal failure to propagate.');
            } catch (RuntimeException $exception) {
                $this->assertSame('journal unavailable', $exception->getMessage());
            }

            $this->assertFalse($this->app->make(ActiveSolutions::class)->isActive('feedback'));
        });

        $this->assertSame(0, SolutionActivationRecord::query()->count());
    }

    public function test_the_activator_needs_a_tenant(): void
    {
        $this->expectException(\App\Domains\Tenancy\Exceptions\TenantNotResolvedException::class);

        $this->app->make(SolutionActivator::class)->activate('feedback', ActivationActor::console());
    }

    public function test_a_change_by_another_process_is_seen_after_an_unchanged_call(): void
    {
        $this->inTenant(function (): void {
            $activator = $this->app->make(SolutionActivator::class);
            $active    = $this->app->make(ActiveSolutions::class);

            $this->assertFalse($active->isActive('feedback'));

            // Another process activates; this scope's memo still says no.
            SolutionActivationRecord::query()->create(['solution_id' => 'feedback', 'status' => 'active', 'activated_at' => now()]);
            $this->assertFalse($active->isActive('feedback'));

            $this->assertSame(ActivationChange::Unchanged, $activator->activate('feedback', ActivationActor::console()));
            $this->assertTrue($active->isActive('feedback'), 'The call dropped the stale memo even though it changed nothing.');
        });
    }

    public function test_the_journal_refuses_updates_and_deletes(): void
    {
        $this->inTenant(fn () => $this->app->make(SolutionActivator::class)->activate('feedback', ActivationActor::console()));
        $event = SolutionActivationEventRecord::query()->firstOrFail();

        try {
            $event->update(['reason' => 'edited']);
            $this->fail('Expected the update to be refused.');
        } catch (LogicException) {
            $this->assertNull($event->fresh()?->reason);
        }

        try {
            $event->delete();
            $this->fail('Expected the delete to be refused.');
        } catch (LogicException) {
            $this->assertSame(1, SolutionActivationEventRecord::query()->count());
        }
    }

    private function inTenant(callable $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant(new RuntimeTenant('tenant-one', 'main', 'one'), $callback(...));
    }
}
