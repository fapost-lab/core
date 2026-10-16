<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Domains\Solution\Enums\ActivationActorKind;
use App\Domains\Solution\Infrastructure\SolutionActivationEventRecord;
use App\Domains\Solution\Infrastructure\SolutionActivationRecord;
use Fapost\Foundation\Solution\Contracts\SolutionEntitlementInterface;
use Fapost\Foundation\Solution\DTO\SolutionEntitlement;
use Fapost\Foundation\Solution\DTO\SolutionGrant;
use Fapost\Foundation\Solution\Enums\SolutionGrantSource;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeSolutionEntitlement;
use Tests\Support\InstallsFakeSolutions;

/**
 * `solutions:activate|deactivate|list` against the seeded tenant `main`.
 */
final class SolutionActivationCommandsTest extends FeatureTestCase
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

    public function test_activate_turns_an_installed_solution_on_as_the_console(): void
    {
        $this->artisan('solutions:activate', ['solution' => 'feedback', '--tenant' => 'main'])
            ->expectsOutputToContain('Solution "feedback" is now active for tenant "main"')
            ->assertExitCode(0);

        $this->assertSame('active', SolutionActivationRecord::query()->where('solution_id', 'feedback')->value('status')->value);
        $this->assertSame(ActivationActorKind::Console, SolutionActivationEventRecord::query()->firstOrFail()->actor_kind);
    }

    public function test_activate_twice_says_so_and_still_exits_zero(): void
    {
        $this->artisan('solutions:activate', ['solution' => 'feedback', '--tenant' => 'main'])->assertExitCode(0);

        $this->artisan('solutions:activate', ['solution' => 'feedback', '--tenant' => 'main'])
            ->expectsOutputToContain('was already active')
            ->assertExitCode(0);

        $this->assertSame(1, SolutionActivationEventRecord::query()->count());
    }

    public function test_activate_refuses_a_solution_that_is_not_installed(): void
    {
        $this->artisan('solutions:activate', ['solution' => 'ghost', '--tenant' => 'main'])
            ->expectsOutputToContain('Solution "ghost" is not installed')
            ->assertExitCode(1);

        $this->assertSame(0, SolutionActivationRecord::query()->count());
    }

    public function test_activate_refuses_a_solution_the_operator_does_not_entitle_and_list_says_why_it_does_not_run(): void
    {
        $this->artisan('solutions:activate', ['solution' => 'feedback', '--tenant' => 'main'])->assertExitCode(0);
        $operator          = new FakeSolutionEntitlement();
        $operator->default = SolutionEntitlement::only(['feedback']);
        $this->app->instance(SolutionEntitlementInterface::class, $operator);
        $this->app->forgetScopedInstances();

        $this->artisan('solutions:activate', ['solution' => 'survey', '--tenant' => 'main'])
            ->expectsOutputToContain('not included in what this tenant is entitled to')
            ->assertExitCode(1);

        $operator->default = SolutionEntitlement::only([]);
        $this->app->forgetScopedInstances();

        $this->artisan('solutions:list', ['--tenant' => 'main'])
            ->expectsTable(
                ['Solution', 'Name', 'Version', 'Installed', 'Stored', 'Entitled', 'Effective', 'Source'],
                [
                    ['feedback', 'Feedback', '1.0.0', 'yes', 'active', 'no', 'inactive', '-'],
                    ['survey', 'Survey', '1.0.0', 'yes', 'none', 'no', 'inactive', '-'],
                ],
            )
            ->assertExitCode(0);

        $this->assertSame(1, SolutionActivationEventRecord::query()->count(), 'Refusing and losing the entitlement journal nothing.');
    }

    public function test_deactivate_turns_it_off_and_keeps_the_reason(): void
    {
        $this->artisan('solutions:activate', ['solution' => 'feedback', '--tenant' => 'main'])->assertExitCode(0);

        $this->artisan('solutions:deactivate', ['solution' => 'feedback', '--tenant' => 'main', '--reason' => 'not needed'])
            ->expectsOutputToContain('is now inactive')
            ->assertExitCode(0);

        $this->assertSame('not needed', SolutionActivationEventRecord::query()->where('action', 'deactivated')->value('reason'));
    }

    public function test_deactivate_refuses_a_reason_longer_than_the_column(): void
    {
        $this->artisan('solutions:activate', ['solution' => 'feedback', '--tenant' => 'main'])->assertExitCode(0);

        $this->artisan('solutions:deactivate', ['solution' => 'feedback', '--tenant' => 'main', '--reason' => str_repeat('x', 256)])
            ->expectsOutputToContain('at most 255 characters')
            ->assertExitCode(1);

        $this->assertSame('active', SolutionActivationRecord::query()->value('status')->value);
    }

    public function test_deactivate_of_a_solution_that_was_never_on_is_not_an_error(): void
    {
        $this->artisan('solutions:deactivate', ['solution' => 'survey', '--tenant' => 'main'])
            ->expectsOutputToContain('was not active')
            ->assertExitCode(0);
    }

    public function test_the_tenant_option_is_required(): void
    {
        $this->artisan('solutions:activate', ['solution' => 'feedback'])
            ->expectsOutputToContain('The --tenant=<slug> option is required')
            ->assertExitCode(1);

        $this->assertSame(0, SolutionActivationRecord::query()->count());
    }

    public function test_an_unknown_tenant_is_refused_by_every_command(): void
    {
        foreach (['solutions:activate' => ['solution' => 'feedback'], 'solutions:deactivate' => ['solution' => 'feedback'], 'solutions:list' => []] as $command => $arguments) {
            $this->artisan($command, $arguments + ['--tenant' => 'nobody'])
                ->expectsOutputToContain('No active tenant with the slug "nobody"')
                ->assertExitCode(1);
        }
    }

    public function test_list_shows_installed_solutions_and_orphans_with_their_effective_state(): void
    {
        $this->artisan('solutions:activate', ['solution' => 'feedback', '--tenant' => 'main'])->assertExitCode(0);
        $this->artisan('solutions:activate', ['solution' => 'survey', '--tenant' => 'main'])->assertExitCode(0);

        // The survey package is removed from the image: its row stays, and it is no longer active.
        $this->installSolutions('feedback');

        $this->artisan('solutions:list', ['--tenant' => 'main'])
            ->expectsTable(
                ['Solution', 'Name', 'Version', 'Installed', 'Stored', 'Entitled', 'Effective', 'Source'],
                [
                    ['feedback', 'Feedback', '1.0.0', 'yes', 'active', 'yes', 'active', 'manual'],
                    ['survey', '-', '-', 'no', 'active', '-', 'inactive', '-'],
                ],
            )
            ->assertExitCode(0);
    }

    public function test_list_marks_an_installed_solution_nobody_activated(): void
    {
        $this->artisan('solutions:list', ['--tenant' => 'main'])
            ->expectsTable(
                ['Solution', 'Name', 'Version', 'Installed', 'Stored', 'Entitled', 'Effective', 'Source'],
                [
                    ['feedback', 'Feedback', '1.0.0', 'yes', 'none', 'yes', 'inactive', '-'],
                    ['survey', 'Survey', '1.0.0', 'yes', 'none', 'yes', 'inactive', '-'],
                ],
            )
            ->assertExitCode(0);

        $this->assertSame(0, SolutionActivationRecord::query()->count(), 'Listing writes nothing.');
    }

    public function test_a_granted_solution_is_on_with_nothing_written_and_cannot_be_turned_off_from_the_command_line(): void
    {
        $operator          = new FakeSolutionEntitlement();
        $operator->default = SolutionEntitlement::grants([new SolutionGrant('feedback', SolutionGrantSource::Plan)], ['survey']);
        $this->app->instance(SolutionEntitlementInterface::class, $operator);
        $this->app->forgetScopedInstances();

        $this->artisan('solutions:activate', ['solution' => 'feedback', '--tenant' => 'main'])
            ->expectsOutputToContain('included by its plan; nothing was written')
            ->assertExitCode(0);

        $this->artisan('solutions:deactivate', ['solution' => 'feedback', '--tenant' => 'main'])
            ->expectsOutputToContain('cannot be turned off here')
            ->assertExitCode(1);

        $this->artisan('solutions:activate', ['solution' => 'survey', '--tenant' => 'main'])->assertExitCode(0);

        $this->artisan('solutions:list', ['--tenant' => 'main'])
            ->expectsTable(
                ['Solution', 'Name', 'Version', 'Installed', 'Stored', 'Entitled', 'Effective', 'Source'],
                [
                    ['feedback', 'Feedback', '1.0.0', 'yes', 'none', 'yes', 'active', 'plan'],
                    ['survey', 'Survey', '1.0.0', 'yes', 'active', 'yes', 'active', 'manual'],
                ],
            )
            ->assertExitCode(0);

        $this->assertSame(1, SolutionActivationEventRecord::query()->count(), 'Only the manual activation is journaled.');
    }
}
