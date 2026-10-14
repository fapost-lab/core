<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Solution;

use App\Domains\Solution\Console\ConsoleExtensionRegistry;
use App\Domains\Solution\Console\SolutionDeclaration;
use App\Domains\Solution\Providers\SolutionServiceProvider;
use App\Http\Shell\ApplicationRegistry;
use Fapost\Foundation\Console\Contracts\ConsoleExtensionsInterface;
use Fapost\Foundation\Console\DTO\ConsoleApplication;
use Fapost\Foundation\Console\DTO\ContextAction;
use Fapost\Foundation\Console\DTO\DashboardWidget;
use Fapost\Foundation\Console\DTO\MenuItem;
use Fapost\Foundation\Console\DTO\SettingsTab;
use Fapost\Foundation\Console\DTO\ShellElement;
use Fapost\Foundation\Console\DTO\TableColumn;
use Fapost\Foundation\Console\Enums\ConsoleTarget;
use Fapost\Foundation\Console\Enums\DashboardTarget;
use Fapost\Foundation\Console\Enums\SettingsTarget;
use Fapost\Foundation\Console\Enums\ShellSlot;
use Fapost\Foundation\Console\Exceptions\InvalidConsoleDeclarationException;
use Tests\Support\InstallsShowcaseSolution;
use Tests\TestCase;

/**
 * The console registry binds every declaration to the Solution whose provider is booting and refuses what a Solution
 * may not declare. No database: registration happens at boot, before any tenant.
 */
final class ConsoleExtensionRegistryTest extends TestCase
{
    use InstallsShowcaseSolution;

    protected function tearDown(): void
    {
        $this->removeShowcase();

        parent::tearDown();
    }

    public function test_a_solution_declares_from_boot_and_every_declaration_is_bound_to_its_id(): void
    {
        $this->installShowcase(publish: false);

        $registry = $this->app->make(ConsoleExtensionRegistry::class);

        $this->assertSame($registry, $this->app->make(ConsoleExtensionsInterface::class));
        $this->assertSame(['showcase'], array_keys($registry->applications()));
        $this->assertSame('solutions.showcase.board', $registry->applicationOf('showcase')?->entryRoute);

        foreach ([ContextAction::class, ShellElement::class, DashboardWidget::class, SettingsTab::class, TableColumn::class] as $point) {
            $declarations = $registry->declarations($point);

            $this->assertNotSame([], $declarations, $point);
            $this->assertSame(['showcase'], array_values(array_unique(array_map(static fn (SolutionDeclaration $declaration): string => $declaration->solutionId, $declarations))), $point);
        }

        $this->assertSame(['showcase'], array_keys($registry->tenantRoutes()));
        $this->assertSame(['showcase'], array_keys($registry->assistantRouteClosures()));
        $this->assertSame(
            ['Actions/Note', 'Settings/Assistant', 'Settings/Tenant', 'Shell/Helper', 'Shell/Status', 'Widgets/Stats'],
            $registry->components('showcase'),
        );
    }

    public function test_declarations_are_ordered_by_order_then_solution_then_registration(): void
    {
        $registry = new ConsoleExtensionRegistry();

        $registry->enter('zeta');
        $registry->dashboardWidget(new DashboardWidget(DashboardTarget::Console, 'first', 'zeta::A'));
        $registry->dashboardWidget(new DashboardWidget(DashboardTarget::Console, 'early', 'zeta::B', order: -1));
        $registry->leave();
        $registry->enter('alpha');
        $registry->dashboardWidget(new DashboardWidget(DashboardTarget::Console, 'second', 'alpha::A'));
        $registry->leave();

        $this->assertSame(
            ['zeta.early', 'alpha.second', 'zeta.first'],
            array_map(static fn (SolutionDeclaration $declaration): string => $declaration->id(), $registry->declarations(DashboardWidget::class)),
        );
    }

    public function test_a_declaration_outside_a_booting_provider_is_refused(): void
    {
        $this->assertRefused(new ConsoleExtensionRegistry(), null, 'No Solution provider is booting');
    }

    public function test_a_closed_registry_refuses_declarations(): void
    {
        $registry = new ConsoleExtensionRegistry();
        $registry->enter('alpha');
        $registry->freeze();

        $this->assertRefused($registry, 'alpha', 'closed');
    }

    public function test_a_solution_cannot_declare_in_another_namespace(): void
    {
        $cases = [
            'label'     => static fn (ConsoleExtensionRegistry $registry) => $registry->settingsTab(new SettingsTab(SettingsTarget::Tenant, 'tab', 'beta::console.tab', 'alpha::Tab')),
            'component' => static fn (ConsoleExtensionRegistry $registry) => $registry->shellElement(new ShellElement(ShellSlot::Floating, 'el', 'beta::Shell/El')),
            'route'     => static fn (ConsoleExtensionRegistry $registry) => $registry->contextAction(ContextAction::link(ConsoleTarget::ContactView, 'go', 'alpha::console.go', 'solutions.beta.page')),
            'menu'      => static fn (ConsoleExtensionRegistry $registry) => $registry->application(new ConsoleApplication('alpha::console.app', 'box', 'solutions.alpha.home', [new MenuItem('alpha::console.x', 'solutions.beta.x')])),
        ];

        foreach ($cases as $case => $declare) {
            $registry = new ConsoleExtensionRegistry();
            $registry->enter('alpha');

            try {
                $declare($registry);
                $this->fail("A foreign {$case} was accepted.");
            } catch (InvalidConsoleDeclarationException $exception) {
                $this->assertSame('alpha', $exception->solutionId, $case);
                $this->assertStringContainsString('its own', $exception->getMessage(), $case);
            }
        }
    }

    public function test_a_solution_has_at_most_one_application_and_unique_keys_per_point_and_target(): void
    {
        $registry = new ConsoleExtensionRegistry();
        $registry->enter('alpha');
        $registry->application(new ConsoleApplication('alpha::console.app', 'box', 'solutions.alpha.home'));
        $registry->contextAction(ContextAction::dialog(ConsoleTarget::ContactView, 'go', 'alpha::console.go', 'alpha::Go'));
        // The same key on another target is another declaration.
        $registry->contextAction(ContextAction::dialog(ConsoleTarget::ContactRow, 'go', 'alpha::console.go', 'alpha::Go'));

        $this->expectRefusal(fn () => $registry->application(new ConsoleApplication('alpha::console.app', 'box', 'solutions.alpha.home')), 'second application');
        $this->expectRefusal(fn () => $registry->contextAction(ContextAction::dialog(ConsoleTarget::ContactView, 'go', 'alpha::console.again', 'alpha::Go')), 'twice');
    }

    public function test_a_solution_named_like_a_core_application_cannot_declare_an_application(): void
    {
        $this->assertSame([ApplicationRegistry::CONSOLE, ApplicationRegistry::ADMIN], ConsoleExtensionRegistry::CORE_APPLICATIONS);

        foreach (ConsoleExtensionRegistry::CORE_APPLICATIONS as $id) {
            $registry = new ConsoleExtensionRegistry();
            $registry->enter($id);

            $this->expectRefusal(fn () => $registry->application(new ConsoleApplication("{$id}::console.app", 'box', "solutions.{$id}.home")), 'key of one of Core');
        }
    }

    public function test_with_the_registry_frozen_after_boot_a_late_declaration_is_refused(): void
    {
        config(['solutions.freeze_console_registry' => true]);
        // The application has booted, so the provider's `booted` callback runs at once, as after a real boot.
        (new SolutionServiceProvider($this->app))->boot();

        $this->assertTrue($this->app->make(ConsoleExtensionRegistry::class)->isFrozen());

        $this->expectException(InvalidConsoleDeclarationException::class);
        $this->expectExceptionMessage('closed');

        $this->installShowcase(publish: false);
    }

    public function test_the_registry_stays_open_when_the_flag_is_off(): void
    {
        config(['solutions.freeze_console_registry' => false]);
        (new SolutionServiceProvider($this->app))->boot();

        $this->assertFalse($this->app->make(ConsoleExtensionRegistry::class)->isFrozen());
    }

    public function test_a_provider_cannot_start_booting_inside_another(): void
    {
        $registry = new ConsoleExtensionRegistry();
        $registry->enter('alpha');

        $this->expectRefusal(fn () => $registry->enter('beta'), 'still booting');
    }

    private function assertRefused(ConsoleExtensionRegistry $registry, ?string $solutionId, string $message): void
    {
        try {
            $registry->routes(static fn (): null => null);
            $this->fail('The declaration was accepted.');
        } catch (InvalidConsoleDeclarationException $exception) {
            $this->assertSame($solutionId, $exception->solutionId);
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function expectRefusal(callable $declare, string $message): void
    {
        try {
            $declare();
            $this->fail('The declaration was accepted.');
        } catch (InvalidConsoleDeclarationException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }
}
