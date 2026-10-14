<?php

declare(strict_types=1);

namespace App\Domains\Solution\Console;

use Closure;
use Fapost\Foundation\Console\ConsoleNames;
use Fapost\Foundation\Console\Contracts\ConsoleExtensionsInterface;
use Fapost\Foundation\Console\DTO\ConsoleApplication;
use Fapost\Foundation\Console\DTO\ContextAction;
use Fapost\Foundation\Console\DTO\DashboardWidget;
use Fapost\Foundation\Console\DTO\SettingsTab;
use Fapost\Foundation\Console\DTO\ShellElement;
use Fapost\Foundation\Console\DTO\TableColumn;
use Fapost\Foundation\Console\Exceptions\InvalidConsoleDeclarationException;

/**
 * The console UI every installed Solution declared (ADR solution-console-ui), held per process: it depends on the
 * image, never on a tenant. Which declarations a request shows is decided later, per tenant and user
 * ({@see \App\Http\Shell\SolutionExtensions}).
 *
 * A declaration is accepted only while a Solution provider's `boot()` runs: {@see SolutionServiceProvider} calls
 * {@see self::enter()} and {@see self::leave()} around it, so every declaration is bound to the id of the Solution
 * whose provider is booting, and a Solution can neither declare for another nor from a request or a deferred callback.
 * Names must sit in that Solution's own namespace (`<id>::`, `solutions.<id>.`), keys are unique per Solution, point and
 * target, and a Solution has at most one application. After boot the registry is frozen, as other registries are.
 *
 * @see \App\Domains\Solution\Providers\SolutionServiceProvider
 */
final class ConsoleExtensionRegistry implements ConsoleExtensionsInterface
{
    /**
     * Keys of Core's own applications in the rail ({@see \App\Http\Shell\ApplicationRegistry}). A Solution's
     * application is keyed by the Solution id, so a Solution with one of these ids cannot have an application.
     *
     * @var list<string>
     */
    public const array CORE_APPLICATIONS = ['console', 'admin'];

    private ?string $booting = null;

    private bool $frozen = false;

    private int $sequence = 0;

    /** @var array<string, ConsoleApplication> by Solution id */
    private array $applications = [];

    /** @var list<SolutionDeclaration<ContextAction|ShellElement|DashboardWidget|SettingsTab|TableColumn>> */
    private array $declarations = [];

    /** @var array<string, true> "<id>|<point>|<target>|<key>" */
    private array $keys = [];

    /** @var array<string, list<Closure>> by Solution id */
    private array $routes = [];

    /** @var array<string, list<Closure>> by Solution id */
    private array $assistantRoutes = [];

    /**
     * Opens the window for the Solution whose provider is about to boot.
     *
     * @throws InvalidConsoleDeclarationException when another Solution's window is still open
     */
    public function enter(string $solutionId): void
    {
        if (null !== $this->booting) {
            throw new InvalidConsoleDeclarationException($this->booting, sprintf('Solution "%s" started booting while "%s" was still booting.', $solutionId, $this->booting));
        }

        $this->booting = $solutionId;
    }

    public function leave(): void
    {
        $this->booting = null;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    public function application(ConsoleApplication $application): void
    {
        $id = $this->solution();

        if (in_array($id, self::CORE_APPLICATIONS, true)) {
            throw new InvalidConsoleDeclarationException($id, sprintf('Solution "%s" cannot declare an application: "%s" is the key of one of Core\'s applications.', $id, $id));
        }

        if (isset($this->applications[$id])) {
            throw new InvalidConsoleDeclarationException($id, sprintf('Solution "%s" declared a second application; a Solution has at most one.', $id));
        }

        $this->ownLabel($id, $application->label);
        $this->ownLabel($id, $application->shortLabel);
        $this->ownRoute($id, $application->entryRoute);

        foreach ($application->menu as $item) {
            $this->ownLabel($id, $item->label);
            $this->ownLabel($id, $item->group);
            $this->ownRoute($id, $item->route);
        }

        $this->applications[$id] = $application;
    }

    public function contextAction(ContextAction $action): void
    {
        $id = $this->solution();

        $this->ownLabel($id, $action->label);
        $this->ownRoute($id, $action->route);
        $this->ownComponent($id, $action->component);
        $this->add($id, 'context action', $action->target->value, $action);
    }

    public function shellElement(ShellElement $element): void
    {
        $id = $this->solution();

        $this->ownComponent($id, $element->component);
        $this->add($id, 'shell element', $element->slot->value, $element);
    }

    public function dashboardWidget(DashboardWidget $widget): void
    {
        $id = $this->solution();

        $this->ownComponent($id, $widget->component);
        $this->add($id, 'dashboard widget', $widget->target->value, $widget);
    }

    public function settingsTab(SettingsTab $tab): void
    {
        $id = $this->solution();

        $this->ownLabel($id, $tab->label);
        $this->ownComponent($id, $tab->component);
        $this->add($id, 'settings tab', $tab->target->value, $tab);
    }

    public function tableColumn(TableColumn $column): void
    {
        $id = $this->solution();

        $this->ownLabel($id, $column->label);
        $this->add($id, 'table column', $column->target->value, $column);
    }

    public function routes(Closure $routes): void
    {
        $this->routes[$this->solution()][] = $routes;
    }

    public function assistantRoutes(Closure $routes): void
    {
        $this->assistantRoutes[$this->solution()][] = $routes;
    }

    /**
     * Whether any Solution declared anything at all; when none did, nothing about the tenant needs to be asked.
     */
    public function isEmpty(): bool
    {
        return [] === $this->applications && [] === $this->declarations;
    }

    /**
     * @return array<string, ConsoleApplication> by Solution id, ordered by id
     */
    public function applications(): array
    {
        $applications = $this->applications;
        ksort($applications);

        return $applications;
    }

    public function applicationOf(string $solutionId): ?ConsoleApplication
    {
        return $this->applications[$solutionId] ?? null;
    }

    /**
     * The declarations of one point, ordered by `order`, then Solution id, then registration.
     *
     * @template T of ContextAction|ShellElement|DashboardWidget|SettingsTab|TableColumn
     *
     * @param  class-string<T>  $point
     *
     * @return list<SolutionDeclaration<T>>
     */
    public function declarations(string $point): array
    {
        $found = array_values(array_filter(
            $this->declarations,
            static fn (SolutionDeclaration $declaration): bool => $declaration->declaration instanceof $point,
        ));

        usort($found, static fn (SolutionDeclaration $a, SolutionDeclaration $b): int => [$a->declaration->order, $a->solutionId, $a->sequence] <=> [$b->declaration->order, $b->solutionId, $b->sequence]);

        /** @var list<SolutionDeclaration<T>> $found */
        return $found;
    }

    /**
     * The `<Path>` of every component the Solution's declarations name; each must be listed under `components` in its
     * `fapost-ui.json`.
     *
     * @return list<string> sorted
     */
    public function components(string $solutionId): array
    {
        $paths = [];

        foreach ($this->declarations as $declaration) {
            $component = match (true) {
                $declaration->solutionId !== $solutionId         => null,
                $declaration->declaration instanceof TableColumn => null,
                default                                          => $declaration->declaration->component,
            };

            if (null !== $component) {
                $paths[] = mb_substr($component, mb_strlen($solutionId) + 2);
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    /**
     * @return array<string, list<Closure>> tenant-wide route closures by Solution id
     */
    public function tenantRoutes(): array
    {
        return $this->routes;
    }

    /**
     * @return array<string, list<Closure>> per-assistant route closures by Solution id
     */
    public function assistantRouteClosures(): array
    {
        return $this->assistantRoutes;
    }

    /**
     * @param  ContextAction|ShellElement|DashboardWidget|SettingsTab|TableColumn  $declaration
     */
    private function add(string $id, string $point, string $target, object $declaration): void
    {
        $key = implode('|', [$id, $point, $target, $declaration->key]);

        if (isset($this->keys[$key])) {
            throw new InvalidConsoleDeclarationException($id, sprintf('Solution "%s" declared the %s "%s" on "%s" twice.', $id, $point, $declaration->key, $target));
        }

        $this->keys[$key]     = true;
        $this->declarations[] = new SolutionDeclaration($id, $declaration, $this->sequence++);
    }

    /**
     * The Solution whose provider is booting.
     *
     * @throws InvalidConsoleDeclarationException when the registry is closed or no Solution provider is booting
     */
    private function solution(): string
    {
        if ($this->frozen) {
            throw new InvalidConsoleDeclarationException($this->booting, 'The console registry is closed: declare console UI from the Solution provider\'s boot().');
        }

        return $this->booting ?? throw new InvalidConsoleDeclarationException(null, 'No Solution provider is booting: declare console UI from the Solution provider\'s boot().');
    }

    private function ownLabel(string $id, ?string $label): void
    {
        if (null !== $label && ! str_starts_with($label, $id . '::')) {
            throw new InvalidConsoleDeclarationException($id, sprintf('Label "%s" of Solution "%s" must be a translation key in its own namespace, "%s::…".', $label, $id, $id));
        }
    }

    private function ownRoute(string $id, ?string $route): void
    {
        $prefix = ConsoleNames::ROUTE_PREFIX . $id . '.';

        if (null !== $route && ! str_starts_with($route, $prefix)) {
            throw new InvalidConsoleDeclarationException($id, sprintf('Route "%s" of Solution "%s" must be one of its own, "%s…".', $route, $id, $prefix));
        }
    }

    private function ownComponent(string $id, ?string $component): void
    {
        if (null !== $component && ConsoleNames::solutionOf($component) !== $id) {
            throw new InvalidConsoleDeclarationException($id, sprintf('Component "%s" of Solution "%s" must be one of its own, "%s::<Path>".', $component, $id, $id));
        }
    }
}
