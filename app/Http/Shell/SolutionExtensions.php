<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Solution\Console\ConsoleExtensionRegistry;
use App\Domains\Solution\Console\SolutionDeclaration;
use App\Domains\Staff\Models\User;
use BackedEnum;
use Fapost\Foundation\Console\DTO\ContextAction;
use Fapost\Foundation\Console\DTO\DashboardWidget;
use Fapost\Foundation\Console\DTO\SettingsTab;
use Fapost\Foundation\Console\DTO\ShellElement;
use Fapost\Foundation\Console\DTO\TableColumn;
use Fapost\Foundation\Console\Enums\ConsoleTarget;
use Fapost\Foundation\Console\Enums\ContextActionKind;
use Fapost\Foundation\Console\Enums\DashboardTarget;
use Fapost\Foundation\Console\Enums\SettingsTarget;
use Fapost\Foundation\Console\Enums\ShellSlot;
use Fapost\Foundation\Console\Enums\TableTarget;
use Fapost\Foundation\Console\Enums\Tone;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * The console UI of Solutions a request shows (ADR solution-console-ui): of everything the Solutions declared
 * ({@see ConsoleExtensionRegistry}), what belongs to a Solution served to the tenant ({@see SolutionUi}), whose ability
 * the user has and, for what lives on an assistant, where an assistant is open. Hiding in the browser is never the
 * access check: what is filtered out here never reaches the client, and every Solution route checks again.
 *
 * Gives the applications of the rail, their menus, and the shared prop `extensions` the console renders the other
 * points from. Labels are translated here. Bound `scoped`.
 *
 * @phpstan-import-type Group from NavigationBuilder
 */
final readonly class SolutionExtensions
{
    /** The id a context action's route receives in place of the record it is chosen on. */
    public const string RECORD_PLACEHOLDER = '__record__';

    /**
     * The Core screens that show one record, by the target of their context actions: there the server knows the record.
     */
    private const array RECORD_SCREENS = [
        'contact.view'      => 'console.contacts.show',
        'conversation.view' => 'console.conversations.show',
    ];

    public function __construct(
        private ConsoleExtensionRegistry $registry,
        private SolutionUi $ui,
        private CurrentAssistantInterface $currentAssistant,
    ) {
    }

    /**
     * The declarations of one point the user sees on this request, in display order.
     *
     * @template T of ContextAction|ShellElement|DashboardWidget|SettingsTab|TableColumn
     *
     * @param  class-string<T>  $point
     *
     * @return list<SolutionDeclaration<T>>
     */
    public function visible(User $user, string $point): array
    {
        if ($this->registry->isEmpty()) {
            return [];
        }

        return array_values(array_filter(
            $this->registry->declarations($point),
            fn (SolutionDeclaration $declaration): bool => $this->ui->isServed($declaration->solutionId)
                && $this->allows($user, $declaration->declaration->ability),
        ));
    }

    /**
     * The applications of the Solutions the user may open, ordered by Solution id. A per-assistant application opens
     * on the assistant the Console opens on, and is not offered without one.
     *
     * @return list<ShellApplication>
     */
    public function applications(User $user, ?Assistant $consoleAssistant): array
    {
        $applications = [];

        foreach ($this->registry->applications() as $id => $application) {
            if (! $this->ui->isServed($id) || ! $this->allows($user, $application->ability)) {
                continue;
            }

            $assistant = $application->perAssistant ? $consoleAssistant : null;

            if ($application->perAssistant && (null === $assistant || ! Gate::forUser($user)->allows('view', $assistant))) {
                continue;
            }

            $href = $this->url($application->entryRoute, null === $assistant ? [] : ['assistant' => (string) $assistant->getKey()]);

            if (null === $href) {
                continue;
            }

            $label = $this->text($application->label);

            $applications[] = new ShellApplication(
                key: $id,
                label: $label,
                short: null === $application->shortLabel ? $label : $this->text($application->shortLabel),
                scope: __('console.applications.apps.' . ($application->perAssistant ? ApplicationRegistry::CONSOLE : ApplicationRegistry::ADMIN) . '.scope'),
                icon: $application->icon,
                href: $href,
                external: false,
                pinnedByDefault: false,
                assistant: $assistant,
            );
        }

        return $applications;
    }

    /**
     * The menu of a Solution's application, in the shape of Core's menus: entries without a group first, then the
     * groups in the order their first entry was declared. An entry the user may not see, or whose route is missing, is
     * left out.
     *
     * @return list<Group>
     */
    public function menu(User $user, ShellApplication $application): array
    {
        $declared = $this->registry->applicationOf($application->key);

        if (null === $declared) {
            return [];
        }

        $parameters = null === $application->assistant ? [] : ['assistant' => (string) $application->assistant->getKey()];
        $groups     = [];

        foreach ($declared->menu as $item) {
            $href = $this->allows($user, $item->ability) ? $this->url($item->route, $parameters) : null;

            if (null === $href) {
                continue;
            }

            $key = $item->group ?? '';
            $groups[$key]['label'] ??= null === $item->group ? null : $this->text($item->group);
            $groups[$key]['items'][] = [
                'key'      => $item->route,
                'label'    => $this->text($item->label),
                'href'     => $href,
                'icon'     => $item->icon,
                'external' => false,
                'badge'    => null,
            ];
        }

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => (null === $a['label'] ? 0 : 1) <=> (null === $b['label'] ? 0 : 1));

        return $groups;
    }

    /**
     * The shared prop `extensions`: every point keyed by its target, each target present even when empty.
     *
     * @return array{
     *     shell: array<string, list<array<string, mixed>>>,
     *     actions: array<string, list<array<string, mixed>>>,
     *     widgets: array<string, list<array<string, mixed>>>,
     *     tabs: array<string, list<array<string, mixed>>>,
     *     columns: array<string, list<array<string, mixed>>>
     * }
     */
    public function props(User $user, Request $request): array
    {
        $props = [
            'shell'   => $this->targets(ShellSlot::cases()),
            'actions' => $this->targets(ConsoleTarget::cases()),
            'widgets' => $this->targets(DashboardTarget::cases()),
            'tabs'    => $this->targets(SettingsTarget::cases()),
            'columns' => $this->targets(TableTarget::cases()),
        ];

        if ($this->registry->isEmpty()) {
            return $props;
        }

        $assistant = $this->currentAssistant->isResolved() ? $this->currentAssistant->get() : null;
        // A per-assistant element talks to the Solution's assistant routes, which still require `view`.
        $viewable = null !== $assistant && Gate::forUser($user)->allows('view', $assistant);

        foreach ($this->visible($user, ShellElement::class) as $element) {
            if (! $element->declaration->perAssistant || $viewable) {
                $props['shell'][$element->declaration->slot->value][] = [
                    ...$this->base($element),
                    'component' => $element->declaration->component,
                ];
            }
        }

        foreach ($this->visible($user, ContextAction::class) as $action) {
            $item = $this->action($user, $action, $assistant, $request);

            if (null !== $item) {
                $props['actions'][$action->declaration->target->value][] = $item;
            }
        }

        foreach ($this->visible($user, DashboardWidget::class) as $widget) {
            $props['widgets'][$widget->declaration->target->value][] = [
                ...$this->base($widget),
                'component' => $widget->declaration->component,
                'size'      => $widget->declaration->size->value,
            ];
        }

        foreach ($this->visible($user, SettingsTab::class) as $tab) {
            $props['tabs'][$tab->declaration->target->value][] = [
                ...$this->base($tab),
                'label'     => $this->text($tab->declaration->label),
                'component' => $tab->declaration->component,
            ];
        }

        foreach ($this->visible($user, TableColumn::class) as $column) {
            $props['columns'][$column->declaration->target->value][] = [
                ...$this->base($column),
                'label' => $this->text($column->declaration->label),
                'cell'  => $column->declaration->cell->value,
                // An object even when empty, as the client reads it as a map.
                'tones' => (object) array_map(static fn (Tone $tone): string => $tone->value, $column->declaration->tones),
            ];
        }

        return $props;
    }

    /**
     * @param  list<BackedEnum>  $cases
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function targets(array $cases): array
    {
        $targets = [];

        foreach ($cases as $case) {
            $targets[(string) $case->value] = [];
        }

        return $targets;
    }

    /**
     * @param  SolutionDeclaration<ContextAction|ShellElement|DashboardWidget|SettingsTab|TableColumn>  $declaration
     *
     * @return array{id: string, solution: string, key: string, order: int}
     */
    private function base(SolutionDeclaration $declaration): array
    {
        return [
            'id'       => $declaration->id(),
            'solution' => $declaration->solutionId,
            'key'      => $declaration->declaration->key,
            'order'    => $declaration->declaration->order,
        ];
    }

    /**
     * A context action for the client. A link carries `href` on the screen of the record it is chosen on, otherwise
     * `hrefTemplate` with {@see self::RECORD_PLACEHOLDER} for the client to fill. A link whose route is missing, or needs
     * an assistant where none is open or the user may not view, is left out.
     *
     * @param  SolutionDeclaration<ContextAction>  $action
     *
     * @return array<string, mixed>|null
     */
    private function action(User $user, SolutionDeclaration $action, ?Assistant $assistant, Request $request): ?array
    {
        $declared = $action->declaration;
        $item     = [
            ...$this->base($action),
            'kind'  => $declared->kind->value,
            'label' => $this->text($declared->label),
            'icon'  => $declared->icon,
        ];

        if (ContextActionKind::Dialog === $declared->kind) {
            return [...$item, 'component' => $declared->component];
        }

        $route = Route::getRoutes()->getByName((string) $declared->route);

        if (! $route instanceof RoutingRoute) {
            Log::warning('A Solution context action names a route that does not exist; the action is left out.', ['solution' => $action->solutionId, 'route' => $declared->route]);

            return null;
        }

        $parameters = ['record' => self::RECORD_PLACEHOLDER];

        if (in_array('assistant', $route->parameterNames(), true)) {
            if (null === $assistant || ! Gate::forUser($user)->allows('view', $assistant)) {
                return null;
            }

            $parameters['assistant'] = (string) $assistant->getKey();
        }

        $template = route((string) $declared->route, $parameters, false);
        $record   = $this->shownRecord($declared->target, $request);

        return null === $record
            ? [...$item, 'hrefTemplate' => $template]
            : [...$item, 'href' => str_replace(self::RECORD_PLACEHOLDER, rawurlencode($record), $template)];
    }

    /**
     * The record the request's screen shows, when it is the screen of the action's target.
     */
    private function shownRecord(ConsoleTarget $target, Request $request): ?string
    {
        $screen = self::RECORD_SCREENS[$target->value] ?? null;
        $route  = $request->route();

        if (null === $screen || ! $route instanceof RoutingRoute || $route->getName() !== $screen) {
            return null;
        }

        $record = $route->parameter('record');

        return is_string($record) && '' !== $record ? $record : null;
    }

    private function allows(User $user, ?string $ability): bool
    {
        return null === $ability || Gate::forUser($user)->allows($ability);
    }

    /**
     * A relative URL of a Solution route, or null when the route does not exist or needs an assistant none is given for.
     * Only the parameters the route takes are passed, so a tenant-wide route of a per-assistant application gets no
     * stray query string.
     *
     * @param  array<string, string>  $parameters
     */
    private function url(string $name, array $parameters): ?string
    {
        $route = Route::getRoutes()->getByName($name);

        if (! $route instanceof RoutingRoute) {
            return null;
        }

        $taken = $route->parameterNames();

        if (in_array('assistant', $taken, true) && ! isset($parameters['assistant'])) {
            return null;
        }

        return route($name, array_intersect_key($parameters, array_flip($taken)), false);
    }

    private function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }
}
