<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Credentials\CredentialTypeRegistry;
use App\Domains\Credentials\CredentialWriter;
use App\Domains\Credentials\Models\Credential;
use App\Domains\Credentials\OAuth2\OAuth2GrantRepository;
use App\Domains\Credentials\Types\OAuth2AuthorizationCodeType;
use App\Domains\Staff\Models\User;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Admin\CredentialRequest;
use Fapost\Foundation\Credentials\DTO\CredentialField;
use Fapost\Foundation\Credentials\Enums\CredentialFieldKind;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's saved credentials in the admin panel: the secrets and settings a flow's call node uses to authorize an
 * HTTP request, and the hosts each may be sent to. A tenant-level screen behind `ManageCredentials` (`CredentialPolicy`).
 *
 * The controller orchestrates: it authorizes, hands the work to {@see CredentialWriter} (which validates what the
 * credential's type demands and writes the audit entry) and answers with a page or a redirect carrying a flash for the
 * toast. Props are built field by field and never carry a secret: the edit form shows the secret fields empty, and an
 * empty value on save means "keep the stored one". Only the date the secret last changed is shown.
 */
final class CredentialController extends Controller
{
    public function __construct(
        private readonly CredentialWriter $credentials,
        private readonly CredentialTypeRegistry $types,
        private readonly OAuth2GrantRepository $grants,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Credential::class);

        $table = new DataTable(
            sortable: ['name', 'type', 'secret_changed_at'],
            searchable: ['name'],
            defaultSort: 'name',
        );

        return Inertia::render('Console/Credentials/Index', [
            'table' => $table->respond(
                $request,
                $this->credentials->query(),
                fn (Credential $credential): array => [
                    'id'                => (string) $credential->getKey(),
                    'name'              => $credential->name,
                    'type'              => $credential->type,
                    'type_label'        => $this->typeLabel($credential->type),
                    'allowed_hosts'     => array_values($credential->allowed_hosts),
                    'secret_changed_at' => $credential->secret_changed_at->toIso8601String(),
                    'editUrl'           => $this->url('edit', ['record' => $credential->getKey()]),
                    'deleteUrl'         => $this->url('destroy', ['record' => $credential->getKey()]),
                ],
            ),
            'can' => [
                'create' => Gate::allows('create', Credential::class),
                'update' => Gate::allows('update', new Credential()),
                'delete' => Gate::allows('delete', new Credential()),
            ],
            'urls' => [
                'index'  => $this->url('index'),
                'create' => $this->url('create'),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Credential::class);

        return Inertia::render('Console/Credentials/Create', [
            'types' => $this->typeSchema(),
            'urls'  => ['index' => $this->url('index'), 'submit' => $this->url('store')],
        ]);
    }

    public function store(CredentialRequest $request): RedirectResponse
    {
        $this->credentials->create($this->data($request));

        return $this->backToIndex(trans('console.credentials.created'));
    }

    public function edit(string $record): Response
    {
        $credential = $this->credentials->find($record);

        Gate::authorize('update', $credential);

        return Inertia::render('Console/Credentials/Edit', [
            'credential' => [
                'id'                => (string) $credential->getKey(),
                'name'              => $credential->name,
                'type'              => $credential->type,
                'type_label'        => $this->typeLabel($credential->type),
                'settings'          => $this->settingsOf($credential),
                'allowed_hosts'     => array_values($credential->allowed_hosts),
                'secret_changed_at' => $credential->secret_changed_at->toIso8601String(),
            ],
            'oauth' => $this->connectionOf($credential),
            'types' => $this->typeSchema(),
            'urls'  => [
                'index'  => $this->url('index'),
                'submit' => $this->url('update', ['record' => $credential->getKey()]),
            ],
        ]);
    }

    public function update(CredentialRequest $request, string $record): RedirectResponse
    {
        $this->credentials->update($this->credentials->find($record), $this->data($request));

        return $this->backToIndex(trans('console.credentials.updated'));
    }

    /**
     * From the list: it goes back to the list the user was on, with its search, sort and page.
     */
    public function destroy(string $record): RedirectResponse
    {
        $credential = $this->credentials->find($record);

        Gate::authorize('delete', $credential);

        $this->credentials->delete($credential);

        Inertia::flash('success', trans('console.credentials.deleted'));

        return redirect()->back(fallback: $this->url('index'));
    }

    /**
     * What the writer takes: the type is only read on create (the request does not accept one on a change).
     *
     * @return array{name: mixed, type: mixed, settings: mixed, secrets: mixed, allowed_hosts: mixed}
     */
    private function data(CredentialRequest $request): array
    {
        $data = $request->validated();

        return [
            'name'          => $data['name'] ?? null,
            'type'          => $data['type'] ?? null,
            'settings'      => $data['settings'] ?? [],
            'secrets'       => $data['secrets'] ?? [],
            'allowed_hosts' => $data['allowed_hosts'] ?? [],
        ];
    }

    /**
     * The consent connection of an `oauth2_authorization_code` credential: its state, dates and who connected it, and
     * the callback URL to register at the provider. Never a token. Null for every other type.
     *
     * @return array{status: string, connected_at: string|null, connected_by_name: string|null, refreshed_at: string|null, failure_code: string|null, callback_url: string, urls: array{connect: string, disconnect: string}}|null
     */
    private function connectionOf(Credential $credential): ?array
    {
        if (OAuth2AuthorizationCodeType::ID !== $credential->type) {
            return null;
        }

        $grant = $this->grants->find((string) $credential->getKey());

        return [
            'status'            => $grant?->status->value ?? 'not_connected',
            'connected_at'      => $grant?->connected_at->toIso8601String(),
            'connected_by_name' => null === $grant?->connected_by ? null : User::query()->whereKey($grant->connected_by)->value('name'),
            'refreshed_at'      => $grant?->refreshed_at->toIso8601String(),
            'failure_code'      => $grant?->failure_code,
            'callback_url'      => CredentialOAuthController::callbackUrl(),
            'urls'              => [
                'connect'    => $this->url('oauth.connect', ['record' => $credential->getKey()]),
                'disconnect' => $this->url('oauth.disconnect', ['record' => $credential->getKey()]),
            ],
        ];
    }

    /**
     * The credential's settings keyed by the fields its type declares: nothing else is sent, and no secret field is.
     *
     * @return array<string, string>
     */
    private function settingsOf(Credential $credential): array
    {
        if (!$this->types->has($credential->type)) {
            return [];
        }

        $settings = [];

        foreach ($this->types->get($credential->type)->fields() as $field) {
            $value = $credential->settings[$field->key] ?? null;

            if (!$field->secret && is_string($value)) {
                $settings[$field->key] = $value;
            }
        }

        return $settings;
    }

    /**
     * The credential types as the form renders them: one list from the registry, so a type added later needs no
     * change on the client. Labels are translated where the console has a translation, else passed through.
     *
     * @return list<array{id: string, label: string, fields: list<array{key: string, label: string, secret: bool, required: bool, kind: string, choices: list<array{value: string, label: string}>}>}>
     */
    private function typeSchema(): array
    {
        $schema = [];

        foreach ($this->types->all() as $type) {
            $schema[] = [
                'id'     => $type->id(),
                'label'  => $this->typeLabel($type->id()),
                'fields' => array_map(
                    fn (CredentialField $field): array => [
                        'key'      => $field->key,
                        'label'    => $this->translated("console.credentials.type_fields.{$type->id()}.{$field->key}", $field->label),
                        'secret'   => $field->secret,
                        'required' => $field->required,
                        'kind'     => $field->kind->value,
                        'choices'  => CredentialFieldKind::Select === $field->kind
                            ? array_map(
                                fn (string $choice): array => [
                                    'value' => $choice,
                                    'label' => $this->translated("console.credentials.choices.{$type->id()}.{$field->key}.{$choice}", $choice),
                                ],
                                $field->choices,
                            )
                            : [],
                    ],
                    $type->fields(),
                ),
            ];
        }

        return $schema;
    }

    private function typeLabel(string $typeId): string
    {
        $fallback = $this->types->has($typeId) ? $this->types->get($typeId)->label() : $typeId;

        return $this->translated("console.credentials.types.{$typeId}", $fallback);
    }

    private function translated(string $key, string $fallback): string
    {
        return Lang::has($key) ? (string) trans($key) : $fallback;
    }

    private function backToIndex(string $message): RedirectResponse
    {
        Inertia::flash('success', $message);

        return redirect()->to($this->url('index'));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $action, array $parameters = []): string
    {
        return route('console.admin.credentials.' . $action, $parameters, false);
    }
}
