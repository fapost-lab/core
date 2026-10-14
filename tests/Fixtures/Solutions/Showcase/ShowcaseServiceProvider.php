<?php

declare(strict_types=1);

namespace Tests\Fixtures\Solutions\Showcase;

use Fapost\Foundation\Console\Contracts\ConsoleExtensionsInterface;
use Fapost\Foundation\Console\DTO\ConsoleApplication;
use Fapost\Foundation\Console\DTO\ContextAction;
use Fapost\Foundation\Console\DTO\DashboardWidget;
use Fapost\Foundation\Console\DTO\MenuItem;
use Fapost\Foundation\Console\DTO\SettingsTab;
use Fapost\Foundation\Console\DTO\ShellElement;
use Fapost\Foundation\Console\DTO\TableColumn;
use Fapost\Foundation\Console\Enums\CellType;
use Fapost\Foundation\Console\Enums\ConsoleTarget;
use Fapost\Foundation\Console\Enums\DashboardTarget;
use Fapost\Foundation\Console\Enums\SettingsTarget;
use Fapost\Foundation\Console\Enums\ShellSlot;
use Fapost\Foundation\Console\Enums\TableTarget;
use Fapost\Foundation\Console\Enums\Tone;
use Fapost\Foundation\Console\Enums\WidgetSize;
use Fapost\Foundation\Contracts\CoreRegistrarInterface;
use Fapost\Foundation\Lifecycle\AbstractSolutionServiceProvider;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;

/**
 * The fixture Solution `showcase` (package in ./package): one declaration on every console point, routes of both kinds
 * and a prebuilt `dist/`, so the tests prove the extension contract end to end.
 *
 * Abilities: `showcase.use` is everyone's; `showcase.manage` nobody's but an administrator's (through `Gate::before`).
 * Console UI is declared from `boot()`, after `parent::boot()`, as a Solution must.
 */
final class ShowcaseServiceProvider extends AbstractSolutionServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        $this->loadTranslationsFrom(__DIR__ . '/package/lang', 'showcase');

        Gate::define('showcase.use', static fn (mixed $user): bool => true);
        Gate::define('showcase.manage', static fn (mixed $user): bool => false);

        $console = $this->app->make(ConsoleExtensionsInterface::class);

        $console->application(new ConsoleApplication(
            label: 'showcase::console.app',
            icon: 'kanban',
            entryRoute: 'solutions.showcase.board',
            menu: [
                new MenuItem('showcase::console.menu.board', 'solutions.showcase.board', 'layout-grid'),
                new MenuItem('showcase::console.menu.desk', 'solutions.showcase.desk', 'lamp-desk', 'showcase.manage', 'showcase::console.menu.admin'),
            ],
            ability: 'showcase.use',
            shortLabel: 'showcase::console.short',
        ));

        $console->contextAction(ContextAction::link(ConsoleTarget::ContactView, 'open', 'showcase::console.actions.open', 'solutions.showcase.contact', 'external-link'));
        $console->contextAction(ContextAction::link(ConsoleTarget::ContactRow, 'open', 'showcase::console.actions.open', 'solutions.showcase.contact', 'external-link'));
        $console->contextAction(ContextAction::dialog(ConsoleTarget::ConversationView, 'note', 'showcase::console.actions.note', 'showcase::Actions/Note', 'sticky-note'));
        $console->contextAction(ContextAction::dialog(ConsoleTarget::ContactView, 'secret', 'showcase::console.actions.secret', 'showcase::Actions/Note', ability: 'showcase.manage'));

        $console->shellElement(new ShellElement(ShellSlot::Floating, 'helper', 'showcase::Shell/Helper'));
        $console->shellElement(new ShellElement(ShellSlot::Header, 'status', 'showcase::Shell/Status', perAssistant: true));

        $console->dashboardWidget(new DashboardWidget(DashboardTarget::Console, 'stats', 'showcase::Widgets/Stats', WidgetSize::Double));
        $console->dashboardWidget(new DashboardWidget(DashboardTarget::Admin, 'overview', 'showcase::Widgets/Stats', WidgetSize::Full, 'showcase.manage', -5));

        $console->settingsTab(new SettingsTab(SettingsTarget::Assistant, 'general', 'showcase::console.tabs.general', 'showcase::Settings/Assistant'));
        $console->settingsTab(new SettingsTab(SettingsTarget::Tenant, 'billing', 'showcase::console.tabs.billing', 'showcase::Settings/Tenant'));

        $console->tableColumn(new TableColumn(TableTarget::Contacts, 'score', 'showcase::console.columns.score', CellType::Number, ShowcaseColumnValues::class));
        $console->tableColumn(new TableColumn(
            TableTarget::Conversations,
            'mood',
            'showcase::console.columns.mood',
            CellType::Status,
            ShowcaseColumnValues::class,
            tones: ['happy' => Tone::Success, 'angry' => Tone::Danger],
        ));

        $console->routes(static function (Router $router): void {
            $router->get('board', [ShowcaseController::class, 'board'])->name('board');
            // Hiding the menu entry is no access check: the route checks the same ability.
            $router->get('desk', [ShowcaseController::class, 'desk'])->middleware('can:showcase.manage')->name('desk');
            $router->get('foreign', [ShowcaseController::class, 'foreign'])->name('foreign');
        });

        $console->assistantRoutes(static function (Router $router): void {
            $router->get('contacts/{record}', [ShowcaseController::class, 'contact'])->name('contact');
        });
    }

    protected function registerExtensions(CoreRegistrarInterface $registrar): void
    {
    }
}
