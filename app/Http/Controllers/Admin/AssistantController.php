<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Assistant\DTOs\AssistantLimitStatus;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Services\StaffAssistantService;
use App\Domains\Assistant\Support\ContentLanguages;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\AssistantChannelService;
use App\Domains\Channels\Services\ChannelWebhookSyncOutcome;
use App\Domains\Staff\Models\User;
use App\Http\Controllers\Concerns\PresentsChannels;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Admin\AssistantRequest;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Assistants in the admin panel, inside the console shell's admin mode: the list, creating, viewing (with the
 * assistant's channels), changing and deleting an assistant, with the rights and the limit the Filament resource had.
 *
 * {@see StaffAssistantService} bounds every read by the tenant and, for anyone but an administrator, by the
 * assignment: an assistant the user may not see is a 404. Creating is closed at the tenant's assistant limit for
 * everyone, administrators included (the policy cannot do that: they pass `Gate::before`).
 *
 * The channels section uses the console's channel rows; its writes are {@see AssistantChannelController}'s.
 */
final class AssistantController extends Controller
{
    use PresentsChannels;

    public function __construct(
        private readonly StaffAssistantService $assistants,
        private readonly AssistantChannelService $channels,
        private readonly ChannelWebhookSyncOutcome $outcome,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Assistant::class);

        $user      = $this->user($request);
        $limit     = $this->assistants->limit();
        $languages = ContentLanguages::options();
        $table     = new DataTable(
            sortable: ['name', 'is_active', 'default_language', 'updated_at'],
            searchable: ['name'],
            defaultSort: 'name',
        );

        return Inertia::render('Console/Assistants/Index', [
            'table' => $table->respond(
                $request,
                $this->assistants->query($user),
                fn (Assistant $assistant): array => [
                    'id'              => (string) $assistant->getKey(),
                    'name'            => $assistant->name,
                    'isActive'        => $assistant->is_active,
                    'defaultLanguage' => $assistant->default_language,
                    'languageLabel'   => $languages[$assistant->default_language] ?? $assistant->default_language,
                    'updatedAt'       => $assistant->updated_at?->toIso8601String(),
                    'showUrl'         => $this->url('view', $assistant),
                    'editUrl'         => $this->url('edit', $assistant),
                    'consoleUrl'      => $this->consoleUrl($assistant),
                    'can'             => [
                        'view'   => $user->can('view', $assistant),
                        'update' => $user->can('update', $assistant),
                    ],
                ],
            ),
            'limit' => $this->limitProps($limit),
            'can'   => [
                'create' => Gate::allows('create', Assistant::class) && ! $limit->reached,
            ],
            'urls' => [
                'index'  => $this->url('index'),
                'create' => $this->url('create'),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Assistant::class);

        // Closed at the limit, but only on opening: a form opened below it and saved after another request took the
        // last slot still reaches the service, which refuses with a message.
        abort_if($this->assistants->limit()->reached, HttpResponse::HTTP_FORBIDDEN);

        return Inertia::render('Console/Assistants/Create', [
            'languages' => $this->languageOptions(),
            'urls'      => [
                'index'  => $this->url('index'),
                'submit' => route('console.admin.assistants.store', [], false),
            ],
        ]);
    }

    public function store(AssistantRequest $request): RedirectResponse
    {
        try {
            $this->assistants->create($this->user($request), $request->fields());
        } catch (RecordLimitReachedException $exception) {
            Inertia::flash('error', trans('console.assistants.limit_reached') . '. ' . $exception->getMessage());

            // Not back: the create page is closed at the limit. The list shows the limit hint.
            return redirect()->to($this->url('index'));
        }

        Inertia::flash('success', trans('console.assistants.created'));

        return redirect()->to($this->url('index'));
    }

    public function show(Request $request, string $record): Response
    {
        $user      = $this->user($request);
        $assistant = $this->assistants->findFor($user, $record);

        Gate::authorize('view', $assistant);

        $languages = ContentLanguages::options();

        return Inertia::render('Console/Assistants/Show', [
            'assistant' => [
                'id'              => (string) $assistant->getKey(),
                'name'            => $assistant->name,
                'isActive'        => $assistant->is_active,
                'defaultLanguage' => $assistant->default_language,
                'languageLabel'   => $languages[$assistant->default_language] ?? $assistant->default_language,
                'createdAt'       => $assistant->created_at?->toIso8601String(),
                'updatedAt'       => $assistant->updated_at?->toIso8601String(),
            ],
            // As the Filament relation manager: shown to whoever sees the assistant and may see channels.
            'channels' => Gate::allows('viewAny', Channel::class) ? $this->channelsSection($request, $assistant) : null,
            'can'      => [
                'update' => $user->can('update', $assistant),
            ],
            'urls' => [
                'index'   => $this->url('index'),
                'show'    => $this->url('view', $assistant),
                'edit'    => $this->url('edit', $assistant),
                'console' => $this->consoleUrl($assistant),
            ],
        ]);
    }

    public function edit(Request $request, string $record): Response
    {
        $user      = $this->user($request);
        $assistant = $this->assistants->findFor($user, $record);

        Gate::authorize('update', $assistant);

        return Inertia::render('Console/Assistants/Edit', [
            'assistant' => [
                'id'              => (string) $assistant->getKey(),
                'name'            => $assistant->name,
                'isActive'        => $assistant->is_active,
                'defaultLanguage' => $assistant->default_language,
            ],
            'languages' => $this->languageOptions(),
            'can'       => [
                'delete' => $user->can('delete', $assistant),
            ],
            'urls' => [
                'index'   => $this->url('index'),
                'show'    => $this->url('view', $assistant),
                'submit'  => route('console.admin.assistants.update', ['record' => $assistant->getKey()], false),
                'destroy' => route('console.admin.assistants.destroy', ['record' => $assistant->getKey()], false),
            ],
        ]);
    }

    public function update(AssistantRequest $request, string $record): RedirectResponse
    {
        $assistant = $this->assistants->findFor($this->user($request), $record);

        $this->assistants->update($assistant, $request->fields());

        Inertia::flash('success', trans('console.assistants.updated'));

        return redirect()->to($this->url('index'));
    }

    public function destroy(Request $request, string $record): RedirectResponse
    {
        $assistant = $this->assistants->findFor($this->user($request), $record);

        Gate::authorize('delete', $assistant);

        $this->assistants->delete($assistant);

        // The channels went with the assistant; a provider that did not confirm removing a webhook is noted per request.
        $this->outcome->hasFailures()
            ? Inertia::flash('error', trans('console.assistants.provider_failed.deleted'))
            : Inertia::flash('success', trans('console.assistants.deleted'));

        return redirect()->to($this->url('index'));
    }

    /**
     * The assistant's channels as the console lists them, with the admin panel's URLs and the channel policy's answers.
     *
     * @return array<string, mixed>
     */
    private function channelsSection(Request $request, Assistant $assistant): array
    {
        $probe = $this->channels->abilityProbe($assistant);
        $table = new DataTable(
            sortable: ['type', 'is_active', 'updated_at'],
            searchable: [],
            defaultSort: '-updated_at',
        );
        $url = fn (string $action, array $parameters): string => $this->channelUrl($action, $assistant, $parameters);

        return [
            'table' => $table->respond(
                $request,
                $this->channels->query($assistant),
                fn (Channel $channel): array => $this->channelRow($channel, $url),
            ),
            'can' => [
                'update' => Gate::allows('update', $probe),
                'delete' => Gate::allows('delete', $probe),
                'rotate' => Gate::allows('rotateWebhook', $probe),
            ],
        ];
    }

    /**
     * @return array{reached: bool, hint: string|null}
     */
    private function limitProps(AssistantLimitStatus $limit): array
    {
        return [
            'reached' => $limit->reached,
            'hint'    => $limit->reached && null !== $limit->limit
                ? trans('staff.assistants.limit.hint', ['current' => $limit->current, 'limit' => $limit->limit])
                : null,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function languageOptions(): array
    {
        $options = [];

        foreach (ContentLanguages::options() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        // The `admin` stack signs the user in before any action runs.
        return $user instanceof User ? $user : throw new LogicException('The admin stack runs for a signed-in staff user.');
    }

    /**
     * The assistant's own console, as the Filament "Manage" action opened it.
     */
    private function consoleUrl(Assistant $assistant): string
    {
        return route('filament.assistant.pages.dashboard', ['tenant' => $assistant->getKey()], false);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function channelUrl(string $action, Assistant $assistant, array $parameters): string
    {
        return route('console.admin.assistants.channels.' . $action, [
            'record'  => $assistant->getKey(),
            'channel' => $parameters['record'],
        ], false);
    }

    /**
     * A relative URL of one of the pages Filament served: `index`, `create`, `view` or `edit`.
     */
    private function url(string $page, ?Assistant $assistant = null): string
    {
        return route(
            'filament.admin.resources.assistants.' . $page,
            null === $assistant ? [] : ['record' => $assistant->getKey()],
            false,
        );
    }
}
