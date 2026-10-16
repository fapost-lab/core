<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Solution\Console\ConsoleExtensionRegistry;
use App\Domains\Solution\Console\SolutionDeclaration;
use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\DTO\SolutionActivation;
use App\Domains\Solution\DTO\SolutionActivationEvent;
use App\Domains\Solution\DTO\UiRangeMismatch;
use App\Domains\Solution\Enums\SolutionActivationStatus;
use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\InstalledSolutions;
use App\Domains\Solution\Services\PublishedSolutionUi;
use App\Domains\Solution\Services\SolutionUiCompatibility;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Fapost\Foundation\Console\DTO\ContextAction;
use Fapost\Foundation\Console\DTO\DashboardWidget;
use Fapost\Foundation\Console\DTO\SettingsTab;
use Fapost\Foundation\Console\DTO\ShellElement;
use Fapost\Foundation\Console\DTO\TableColumn;

/**
 * The props of the Solutions screen of the admin panel: every Solution installed in this image with a valid manifest,
 * whether the tenant has it on, what it registers (the actions of its manifest and its console declarations, as the
 * registry holds them) and, when it ships a console UI, whether the bundle is published and fits the kit Core ships.
 * A Solution whose package is gone but whose activation is still stored is listed apart, so it can be put out.
 *
 * Only valid Solutions are shown: the catalog is closed to a package whose manifest fails, and `platform:update`
 * refuses it before a tenant could see it. Built per request, with no state of its own: it reads the tenant's activation through the scoped registry.
 *
 * @phpstan-type Registration array{point: string, target: string, key: string, label: string|null}
 */
final readonly class SolutionsPresenter
{
    /**
     * The console points, by the name the screen shows them under.
     *
     * @var array<string, class-string<ContextAction|ShellElement|DashboardWidget|SettingsTab|TableColumn>>
     */
    private const array POINTS = [
        'context_action'   => ContextAction::class,
        'shell_element'    => ShellElement::class,
        'dashboard_widget' => DashboardWidget::class,
        'settings_tab'     => SettingsTab::class,
        'table_column'     => TableColumn::class,
    ];

    public function __construct(
        private InstalledSolutions $installed,
        private ActiveSolutions $active,
        private SolutionActivationStoreInterface $store,
        private ConsoleExtensionRegistry $registry,
        private PublishedSolutionUi $published,
        private SolutionUiCompatibility $compatibility,
    ) {
    }

    /**
     * @return array{solutions: list<array<string, mixed>>, missing: list<array<string, mixed>>}
     */
    public function page(): array
    {
        $stored = [];
        $latest = $this->store->latestEvents();

        foreach ($this->store->all() as $row) {
            $stored[$row->solutionId] = $row;
        }

        $solutions = array_map(
            fn (InstalledSolution $solution): array => $this->solution($solution, $stored[$solution->id] ?? null, $latest[$solution->id] ?? null),
            $this->installed->all(),
        );

        $missing = [];

        foreach ($stored as $id => $row) {
            if (! $this->installed->has($id) && SolutionActivationStatus::Active === $row->status) {
                $missing[] = [
                    'id'         => $id,
                    'lastChange' => $this->lastChange($latest[$id] ?? null),
                    'urls'       => ['deactivate' => $this->url('console.admin.solutions.deactivate', $id)],
                ];
            }
        }

        return ['solutions' => $solutions, 'missing' => $missing];
    }

    /**
     * Whether this tenant may turn the Solution on: it is installed and its console UI fits the kit, Vue and Inertia
     * this platform ships ({@see self::refusal()} says why not). The one place the entitlement step
     * (`solution-entitlement-seam`) will also be asked what the tenant is allowed to have; there is no such check yet.
     * The controller asks again before it activates.
     */
    public function isActivatable(string $solutionId): bool
    {
        return $this->installed->has($solutionId) && [] === $this->mismatches($solutionId);
    }

    /**
     * Why the Solution cannot be turned on, in the reader's language, or null when nothing stands in the way: a console
     * UI built for a kit, Vue or Inertia this platform does not ship (`adr-20261014-solution-console-ui`, Stability).
     */
    public function refusal(string $solutionId): ?string
    {
        $mismatches = $this->mismatches($solutionId);

        if ([] === $mismatches) {
            return null;
        }

        return implode(' ', array_map(
            fn (UiRangeMismatch $mismatch): string => (string) __($mismatch->unreadable ? 'console.solutions.kit.unreadable' : 'console.solutions.kit.mismatch', [
                'runtime'  => (string) __('console.solutions.kit.runtime.' . $mismatch->component),
                'required' => $mismatch->required,
                'provided' => $mismatch->provided,
            ]),
            $mismatches,
        ));
    }

    /**
     * Whether the screen has a Solution of this id to act on: installed, or with an activation still stored.
     */
    public function isKnown(string $solutionId): bool
    {
        if ($this->installed->has($solutionId)) {
            return true;
        }

        foreach ($this->store->all() as $row) {
            if ($row->solutionId === $solutionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<UiRangeMismatch>
     */
    private function mismatches(string $solutionId): array
    {
        $bundle = $this->published->find($solutionId);

        return null === $bundle ? [] : $this->compatibility->mismatches($bundle);
    }

    /**
     * @return array<string, mixed>
     */
    private function solution(InstalledSolution $solution, ?SolutionActivation $stored, ?SolutionActivationEvent $latest): array
    {
        return [
            'id'          => $solution->id,
            'name'        => $solution->name,
            'description' => $solution->description,
            'package'     => $solution->package,
            'version'     => $solution->version,
            'active'      => $this->active->isActive($solution->id),
            'actions'     => $solution->actions,
            'ui'          => $this->ui($solution),
            'lastChange'  => null === $stored ? null : $this->lastChange($latest),
            'canActivate' => $this->isActivatable($solution->id),
            'urls'        => [
                'activate'   => $this->url('console.admin.solutions.activate', $solution->id),
                'deactivate' => $this->url('console.admin.solutions.deactivate', $solution->id),
            ],
        ];
    }

    /**
     * What the Solution adds to the console, or null when it ships none.
     *
     * @return array{state: string, application: string|null, registrations: list<Registration>, pages: list<string>, mismatches: list<array{component: string, required: string, provided: string, unreadable: bool}>}|null
     */
    private function ui(InstalledSolution $solution): ?array
    {
        $application   = $this->registry->applicationOf($solution->id);
        $registrations = $this->registrations($solution->id);

        if (null === $solution->ui && null === $application && [] === $registrations) {
            return null;
        }

        $bundle = $this->published->find($solution->id);

        return [
            'state'         => null === $bundle ? 'not_published' : 'published',
            'application'   => null === $application ? null : $this->text($application->label),
            'registrations' => $registrations,
            'pages'         => null === $bundle ? [] : array_keys($bundle->pages),
            'mismatches'    => null === $bundle ? [] : array_map(
                static fn (UiRangeMismatch $mismatch): array => [
                    'component'  => $mismatch->component,
                    'required'   => $mismatch->required,
                    'provided'   => $mismatch->provided,
                    'unreadable' => $mismatch->unreadable,
                ],
                $this->compatibility->mismatches($bundle),
            ),
        ];
    }

    /**
     * @return list<Registration>
     */
    private function registrations(string $solutionId): array
    {
        $found = [];

        foreach (self::POINTS as $point => $class) {
            foreach ($this->registry->declarations($class) as $declaration) {
                if ($declaration->solutionId !== $solutionId) {
                    continue;
                }

                $found[] = $this->registration($point, $declaration);
            }
        }

        return $found;
    }

    /**
     * @param  SolutionDeclaration<ContextAction|ShellElement|DashboardWidget|SettingsTab|TableColumn>  $declaration
     *
     * @return Registration
     */
    private function registration(string $point, SolutionDeclaration $declaration): array
    {
        $declared = $declaration->declaration;

        return [
            'point'  => $point,
            'target' => $declared instanceof ShellElement ? $declared->slot->value : $declared->target->value,
            'key'    => $declared->key,
            'label'  => $declared instanceof ContextAction || $declared instanceof SettingsTab || $declared instanceof TableColumn ? $this->text($declared->label) : null,
        ];
    }

    /**
     * @return array{action: string, actor: string|null, at: string, atLabel: string}|null
     */
    private function lastChange(?SolutionActivationEvent $last): ?array
    {
        if (null === $last) {
            return null;
        }

        return [
            'action'  => $last->action->value,
            'actor'   => $last->actorLabel,
            'at'      => $this->utc($last->occurredAt)->toIso8601String(),
            'atLabel' => $this->utc($last->occurredAt)->locale(app()->getLocale())->isoFormat('LLL') . ' UTC',
        ];
    }

    private function utc(DateTimeInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->utc();
    }

    private function url(string $route, string $solutionId): string
    {
        return route($route, ['solution' => $solutionId], false);
    }

    private function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }
}
