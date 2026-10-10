<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Support\ContentLanguages;
use App\Domains\Assistant\Support\CountryCatalog;
use App\Domains\Flow\Commands\CommandActionType;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\AssistantSettingsService;
use App\Domains\Flow\Services\FlowDraftService;
use App\Domains\Staff\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Console\AssistantSettingsRequest;
use App\Http\Requests\Console\StoreAssistantSettingsFlowRequest;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The assistant's settings on the Inertia console: one form in three tabs (general, commands, advanced), saved as a
 * whole, and a flow created from inside it, which is saved together with the form and opens in the builder.
 *
 * {@see AssistantSettingsService} writes the settings in their canonical shape and bounds every flow it reads by the
 * tenant and the assistant; this controller shapes the form and words the flow limit's refusal as a toast.
 */
final class AssistantSettingsController extends Controller
{
    public function __construct(
        private readonly AssistantSettingsService $settings,
        private readonly FlowDraftService $flows,
    ) {
    }

    public function edit(CountryCatalog $countries): Response
    {
        Gate::authorize(Permission::ManageAssistantSettings->value);

        $assistant = $this->settings->assistant();
        $locales   = $this->settings->locales();
        $existing  = $this->settings->existingFlowIds($this->settings->chosenFlowIds());
        $limit     = $this->flows->limit();

        $defaultFlowId = is_string($assistant->default_flow_id) && in_array($assistant->default_flow_id, $existing, true) ? $assistant->default_flow_id : null;

        return Inertia::render('Console/Settings/Edit', [
            'settings' => [
                'defaultLanguage'    => (string) $assistant->default_language,
                'availableCountries' => array_values(array_filter(is_array($assistant->available_countries) ? $assistant->available_countries : [], 'is_string')),
                'defaultFlowId'      => $defaultFlowId,
                // A flow chosen before and deleted since is no choice; the form says so instead of keeping a dead id.
                'defaultFlowMissing' => null === $defaultFlowId && null !== $assistant->default_flow_id && '' !== $assistant->default_flow_id,
                'fallbackMessage'    => $this->entries($assistant->fallback_message, $locales),
                'busyMessage'        => $this->entries($assistant->busy_message, $locales),
                'commands'           => $this->commands($locales, $existing),
                'settings'           => $this->settings->settingsRows(),
            ],
            'languageLocked' => $this->settings->languageLocked(),
            'languages'      => $locales,
            'options'        => [
                'languages'    => $this->options(ContentLanguages::options()),
                'countries'    => $this->options($countries->options()),
                'flows'        => $this->settings->flowOptions(),
                'commandTypes' => array_map(static fn (CommandActionType $type): array => [
                    'value' => $type->value,
                    'label' => trans('console.settings.commands.types.' . $type->value),
                    'help'  => trans('console.settings.commands.types_help.' . $type->value),
                ], CommandActionType::cases()),
            ],
            'limit' => [
                'reached' => $limit->reached,
                'hint'    => $limit->reached && null !== $limit->limit
                    ? trans('assistant.flows.limit.hint', ['current' => $limit->current, 'limit' => $limit->limit])
                    : null,
            ],
            'can' => [
                // As in Filament the create buttons disappear at the limit; creating a flow also needs the flow permission.
                'createFlow' => Gate::allows('create', FlowDraft::class) && ! $limit->reached,
            ],
            'urls' => [
                'submit'    => $this->url('console.settings.update'),
                'storeFlow' => $this->url('console.settings.store-flow'),
            ],
        ]);
    }

    public function update(AssistantSettingsRequest $request): RedirectResponse
    {
        $this->settings->save($request->fields());

        Inertia::flash('success', trans('console.settings.saved'));

        return redirect()->back(fallback: $this->url('filament.assistant.pages.settings'));
    }

    /**
     * Creates a flow for the default or for a command, saves the form with it chosen, and opens it in the builder.
     */
    public function storeFlow(StoreAssistantSettingsFlowRequest $request): RedirectResponse|HttpResponse
    {
        try {
            $flow = $this->settings->saveWithNewFlow($request->fields(), $request->flowName(), $request->target(), $request->commandIndex());
        } catch (RecordLimitReachedException $exception) {
            Inertia::flash('error', trans('assistant.flows.limit.reached_title') . '. ' . $exception->getMessage());

            return redirect()->back(fallback: $this->url('filament.assistant.pages.settings'));
        }

        // The builder is a separate app, so this is a full navigation, not an Inertia visit.
        return Inertia::location(route('builder.flows.show', ['flow' => $flow->flow_id], false));
    }

    /**
     * The commands for the form: the command without its leading `/` (the field shows it), each localized text as a
     * list of entries in the order of the tabs, and a flow that no longer exists as none, flagged.
     *
     * @param  list<string>  $locales
     * @param  list<string>  $existingFlows
     *
     * @return list<array<string, mixed>>
     */
    private function commands(array $locales, array $existingFlows): array
    {
        $commands = $this->settings->assistant()->commands;
        $rows     = [];

        foreach (is_array($commands) ? $commands : [] as $command) {
            if (! is_array($command)) {
                continue;
            }

            $flowId = is_string($command['flow_id'] ?? null) && '' !== $command['flow_id'] ? $command['flow_id'] : null;
            $exists = null !== $flowId && in_array($flowId, $existingFlows, true);
            // An unknown action is no action: the form asks for one, as Filament's did, instead of saving a guess.
            $type = CommandActionType::tryFrom(is_string($command['type'] ?? null) ? $command['type'] : '');

            $rows[] = [
                'command'     => mb_ltrim(is_string($command['command'] ?? null) ? $command['command'] : '', '/'),
                'type'        => $type?->value ?? '',
                'response'    => $this->entries($command['response'] ?? null, $locales),
                'flowId'      => $exists ? $flowId : null,
                'flowMissing' => null !== $flowId && ! $exists,
                'text'        => $this->entries($command['text'] ?? null, $locales),
            ];
        }

        return $rows;
    }

    /**
     * A localized text as one entry per tab, in the order of the tabs (a JSON object would not keep it).
     *
     * @param  list<string>  $locales
     *
     * @return list<array{locale: string, text: string}>
     */
    private function entries(mixed $value, array $locales): array
    {
        $map = $this->settings->asLocaleMap($value);

        return array_map(static fn (string $locale): array => ['locale' => $locale, 'text' => $map[$locale] ?? ''], $locales);
    }

    /**
     * @param  array<string, string>  $options
     *
     * @return list<array{value: string, label: string}>
     */
    private function options(array $options): array
    {
        $list = [];

        foreach ($options as $value => $label) {
            $list[] = ['value' => (string) $value, 'label' => $label];
        }

        return $list;
    }

    private function url(string $name): string
    {
        return route($name, ['tenant' => (string) $this->settings->assistant()->getKey()], false);
    }
}
