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
use App\Domains\Solution\Services\NoSolutionStorefront;
use App\Domains\Solution\Services\PublishedSolutionUi;
use App\Domains\Solution\Services\SolutionUiCompatibility;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Fapost\Foundation\Console\DTO\ContextAction;
use Fapost\Foundation\Console\DTO\DashboardWidget;
use Fapost\Foundation\Console\DTO\SettingsTab;
use Fapost\Foundation\Console\DTO\ShellElement;
use Fapost\Foundation\Console\DTO\TableColumn;
use Fapost\Foundation\Solution\Contracts\SolutionStorefrontInterface;
use Fapost\Foundation\Solution\DTO\SolutionGrant;
use Fapost\Foundation\Solution\DTO\SolutionOffer;
use Fapost\Foundation\Solution\DTO\SolutionPrice;
use NumberFormatter;
use Throwable;

/**
 * The props of the Solutions screen of the admin panel: every Solution installed in this image with a valid manifest,
 * whether the tenant has it on, what it registers (the actions of its manifest and its console declarations, as the
 * registry holds them), whether the operator grants it (plan or add-on: on, with no Turn off), allows it (the tenant's
 * own toggle) or offers it to connect (the operator's storefront) and, when it ships a console UI, whether the bundle is
 * published and fits the kit Core ships.
 * A Solution whose package is gone but whose activation is still stored is listed apart, so it can be put out.
 * On a self-hosted platform with no storefront of its own it also carries the address where more Solutions are found, from the
 * platform setting {@see self::MARKETPLACE_URL_SETTING}.
 *
 * Only valid Solutions are shown: the catalog is closed to a package whose manifest fails, and `platform:update`
 * refuses it before a tenant could see it. Built per request, with no state of its own: it reads the tenant's activation through the scoped registry.
 *
 * @phpstan-type Registration array{point: string, target: string, key: string, label: string|null}
 */
final readonly class SolutionsPresenter
{
    public const string MARKETPLACE_URL_SETTING = 'solutions.marketplace_url';

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
        private SolutionStorefrontInterface $storefront,
        private TenantContextInterface $tenantContext,
        private PlatformSettingsReaderInterface $settings,
    ) {
    }

    /**
     * @return array{solutions: list<array<string, mixed>>, missing: list<array<string, mixed>>, marketplaceUrl: string|null}
     */
    public function page(): array
    {
        $stored = [];
        $latest = $this->store->latestEvents();

        foreach ($this->store->all() as $row) {
            $stored[$row->solutionId] = $row;
        }

        $offers    = $this->offers();
        $solutions = array_map(
            fn (InstalledSolution $solution): array => $this->solution($solution, $stored[$solution->id] ?? null, $latest[$solution->id] ?? null, $offers[$solution->id] ?? null),
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

        return ['solutions' => $solutions, 'missing' => $missing, 'marketplaceUrl' => $this->marketplaceUrl()];
    }

    /**
     * Whether this tenant may turn the Solution on: it is installed, the operator entitles the tenant to it, and its
     * console UI fits the kit, Vue and Inertia this platform ships ({@see self::refusal()} says why not). An entitlement
     * that cannot be checked refuses (fail closed). The controller asks again before it activates.
     */
    public function isActivatable(string $solutionId): bool
    {
        return $this->installed->has($solutionId) && true === $this->active->isEntitled($solutionId) && [] === $this->mismatches($solutionId);
    }

    /**
     * What the operator offers this tenant for the Solution, or null when it offers nothing, already includes it, or its
     * storefront or its entitlement answer cannot be had. Asks the storefront once.
     */
    public function offer(string $solutionId): ?SolutionOffer
    {
        return $this->installed->has($solutionId) ? ($this->offers()[$solutionId] ?? null) : null;
    }

    /**
     * Why the Solution cannot be turned on, in the reader's language, or null when nothing stands in the way: the
     * operator does not entitle the tenant to it, or its answer could not be had; or a console UI built for a kit, Vue
     * or Inertia this platform does not ship (`adr-20261014-solution-console-ui`, Stability).
     */
    public function refusal(string $solutionId): ?string
    {
        $entitled = $this->active->isEntitled($solutionId);

        if (true !== $entitled) {
            return (string) __(null === $entitled ? 'console.solutions.entitlement.unavailable' : 'console.solutions.entitlement.not_entitled');
        }

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
     * Where to find more Solutions, only on a self-hosted installation (single resolution mode) whose storefront is the
     * default one (no operator sells them); null in host mode (the SaaS tenant has no directory to install from), when
     * another storefront is bound or when the setting is empty.
     */
    private function marketplaceUrl(): ?string
    {
        if (TenancyResolutionMode::Single !== TenancyResolutionMode::fromConfig() || ! $this->storefront instanceof NoSolutionStorefront) {
            return null;
        }

        $url = mb_trim($this->settings->text(self::MARKETPLACE_URL_SETTING));

        return '' === $url ? null : $url;
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
    private function solution(InstalledSolution $solution, ?SolutionActivation $stored, ?SolutionActivationEvent $latest, ?SolutionOffer $offer): array
    {
        $grant = $this->active->grantFor($solution->id);

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
            'canActivate' => null === $grant && $this->isActivatable($solution->id),
            'entitlement' => $this->entitlement($solution, $stored),
            'grant'       => null === $grant ? null : $this->grant($grant),
            'offer'       => null === $offer ? null : $this->offerRow($offer),
            'urls'        => [
                'activate'   => $this->url('console.admin.solutions.activate', $solution->id),
                'deactivate' => $this->url('console.admin.solutions.deactivate', $solution->id),
                'connect'    => $this->url('console.admin.solutions.connect', $solution->id),
            ],
        ];
    }

    /**
     * Whether the operator entitles the tenant to the Solution: `entitled`, `not_entitled` (with the operator's button,
     * if it gives one) or `unavailable` when its answer could not be had. `paused` is a Solution the tenant turned on
     * that does not run because it is not entitled: its activation and data are kept, and it runs again once included.
     *
     * @return array{state: string, paused: bool, action: array{label: string, url: string}|null}
     */
    private function entitlement(InstalledSolution $solution, ?SolutionActivation $stored): array
    {
        $entitlement = $this->active->entitlement();
        $state       = match (true) {
            null === $entitlement               => 'unavailable',
            $entitlement->allows($solution->id) => 'entitled',
            default                             => 'not_entitled',
        };
        $action = 'not_entitled' === $state && null !== $entitlement->actionLabel && null !== $entitlement->actionUrl
            ? ['label' => $entitlement->actionLabel, 'url' => $entitlement->actionUrl]
            : null;

        return [
            'state'  => $state,
            'paused' => 'not_entitled' === $state && SolutionActivationStatus::Active === $stored?->status,
            'action' => $action,
        ];
    }

    /**
     * The operator's offers for this tenant by Solution id: only for installed Solutions it neither grants nor allows,
     * and none while its entitlement answer cannot be had (connecting is refused then). A storefront that fails is
     * reported and offers nothing.
     *
     * @return array<string, SolutionOffer>
     */
    private function offers(): array
    {
        $entitlement = $this->active->entitlement();

        if (null === $entitlement || $entitlement->isUnrestricted()) {
            return [];
        }

        try {
            $offers = $this->storefront->offersFor($this->tenantContext->get()->getId());
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        $found = [];

        foreach ($offers as $offer) {
            if ($this->installed->has($offer->solutionId) && ! $entitlement->allows($offer->solutionId)) {
                $found[$offer->solutionId] ??= $offer;
            }
        }

        return $found;
    }

    /**
     * @return array{source: string, note: string|null, endsAt: string|null, endsAtLabel: string|null}
     */
    private function grant(SolutionGrant $grant): array
    {
        return [
            'source'      => $grant->source->value,
            'note'        => $grant->note,
            'endsAt'      => null === $grant->endsAt ? null : $this->utc($grant->endsAt)->toIso8601String(),
            'endsAtLabel' => null === $grant->endsAt ? null : $this->utc($grant->endsAt)->locale(app()->getLocale())->isoFormat('LL'),
        ];
    }

    /**
     * @return array{name: string, description: string|null, trialDays: int, price: string|null, requestPending: bool}
     */
    private function offerRow(SolutionOffer $offer): array
    {
        return [
            'name'           => $offer->name,
            'description'    => $offer->description,
            'trialDays'      => $offer->trialDays,
            'price'          => null === $offer->price ? null : $this->price($offer->price),
            'requestPending' => $offer->requestPending,
        ];
    }

    /**
     * The price in the reader's language, such as "€9.00 / month" or "€90.00 / 3 months"; the amount is in the currency's
     * minor units, so its fraction digits come from the currency (two for EUR, none for JPY).
     */
    private function price(SolutionPrice $price): string
    {
        $formatter = new NumberFormatter(app()->getLocale() . '@currency=' . $price->currency, NumberFormatter::CURRENCY);
        $digits    = (int) $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
        $amount    = $formatter->formatCurrency($price->amountMinor / 10 ** $digits, $price->currency);

        return trans_choice('console.solutions.offer.period.' . $price->period->value, $price->periodCount, ['amount' => $amount, 'count' => $price->periodCount]);
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
