<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Contact\Services\AssistantContactService;
use App\Domains\Contact\Services\ContactCard;
use App\Domains\Contact\Services\ContactGroupService;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Console\UpdateContactGroupsRequest;
use App\Http\Requests\Console\UpdateContactTagsRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contacts on the Inertia console: the assistant's list with its filters, and a contact's card, where the tags and the
 * groups are the only things that can be changed.
 *
 * {@see AssistantContactService} bounds every read and write by the tenant and by the assistant in the URL (a contact is
 * the assistant's when it wrote to one of its channels); {@see ContactCard} lays out the attributes. The assistant and the
 * URLs are taken before a write, so a write that resets the current assistant cannot break the redirect.
 */
final class ContactController extends Controller
{
    public function __construct(
        private readonly AssistantContactService $contacts,
        private readonly ContactCard $card,
        private readonly ContactGroupService $groups,
        private readonly CurrentAssistantInterface $assistant,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Contact::class);

        $assistant = $this->assistant->get();
        $table     = new DataTable(
            sortable: ['created_at', 'updated_at'],
            searchable: ['external_id'],
            defaultSort: '-updated_at',
            filters: [
                'platform' => fn (Builder $query, string $value): bool => $this->contacts->filterByPlatform($query, $value),
                'language' => fn (Builder $query, string $value): bool => $this->contacts->filterByLanguage($query, $value),
            ],
        );

        return Inertia::render('Console/Contacts/Index', [
            'table' => $table->respond(
                $request,
                $this->contacts->query($assistant),
                fn (Contact $contact): array => [
                    'id'         => (string) $contact->getKey(),
                    'shortId'    => mb_substr((string) $contact->getKey(), 0, 8),
                    'platform'   => $contact->platform->value,
                    'externalId' => $contact->external_id,
                    'name'       => $this->displayName($contact),
                    'language'   => '' === (string) $contact->language ? null : $contact->language,
                    'createdAt'  => $contact->created_at?->toIso8601String(),
                    'viewUrl'    => $this->url('view', ['record' => $contact->getKey()]),
                ],
            ),
            'platforms' => array_map(
                static fn (PlatformEnum $platform): array => [
                    'value' => $platform->value,
                    'label' => trans('console.contacts.platforms.' . $platform->value),
                ],
                PlatformEnum::cases(),
            ),
            'languages' => $this->contacts->languageOptions($assistant),
            'urls'      => ['index' => $this->url('index')],
        ]);
    }

    /*
     * Route parameters reach an action by position, so `$tenant` (the assistant in the URL, already resolved by the
     * console stack) is declared ahead of `$record`.
     */
    public function show(string $tenant, string $record): Response
    {
        $assistant = $this->assistant->get();
        $contact   = $this->contacts->findFor($assistant, $record);

        Gate::authorize('view', $contact);

        $canUpdate = Gate::allows('update', $contact);
        $key       = $contact->getKey();

        return Inertia::render('Console/Contacts/Show', [
            'contact' => [
                'id'         => (string) $key,
                'platform'   => $contact->platform->value,
                'externalId' => $contact->external_id,
                'language'   => '' === (string) $contact->language ? null : $contact->language,
                'username'   => $this->metaText($contact, 'username'),
                'name'       => $this->displayName($contact),
                'createdAt'  => $contact->created_at?->toIso8601String(),
                'updatedAt'  => $contact->updated_at?->toIso8601String(),
                'tags'       => $this->contacts->tags($contact),
                'groups'     => $this->contacts->groups($contact),
            ],
            'card' => $this->card->sections($contact),
            'can'  => ['update' => $canUpdate],
            // What the dialogs offer is the tenant's own vocabulary: it goes only to the one who may change the contact.
            ...($canUpdate ? ['tagSuggestions' => $this->contacts->tagSuggestions()] : []),
            ...($canUpdate && Gate::allows('viewAny', ContactGroup::class) ? ['groupOptions' => $this->groupOptions()] : []),
            'urls' => [
                'index' => $this->url('index'),
                ...($canUpdate ? [
                    'tags'   => $this->url('tags', ['record' => $key]),
                    'groups' => $this->url('groups', ['record' => $key]),
                ] : []),
            ],
        ]);
    }

    public function updateTags(UpdateContactTagsRequest $request, string $tenant, string $record): RedirectResponse
    {
        $assistant = $this->assistant->get();
        $contact   = $this->contacts->findFor($assistant, $record);
        $back      = $this->url('view', ['record' => $contact->getKey()]);

        $this->contacts->syncTags($contact, $request->tags(), (string) $request->user()?->getAuthIdentifier());

        return $this->backToCard($back, trans('console.contacts.tags_updated'));
    }

    public function updateGroups(UpdateContactGroupsRequest $request, string $tenant, string $record): RedirectResponse
    {
        $assistant = $this->assistant->get();
        $contact   = $this->contacts->findFor($assistant, $record);
        $back      = $this->url('view', ['record' => $contact->getKey()]);

        $this->contacts->syncGroups($contact, $request->groupIds());

        return $this->backToCard($back, trans('console.contacts.groups_updated'));
    }

    /**
     * After a write: the contact's card, whatever page the request came from. The message is Inertia flash data, so it
     * reaches the toast once and is not kept in the browser's history.
     */
    private function backToCard(string $url, string $message): RedirectResponse
    {
        Inertia::flash('success', $message);

        return redirect()->to($url);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function groupOptions(): array
    {
        return $this->groups->query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (ContactGroup $group): array => ['id' => (string) $group->getKey(), 'name' => $group->name])
            ->all();
    }

    /**
     * The name the platform gave: first and last name, else `@username`, else none.
     */
    private function displayName(Contact $contact): ?string
    {
        $full = mb_trim(($this->metaText($contact, 'first_name') ?? '') . ' ' . ($this->metaText($contact, 'last_name') ?? ''));

        if ('' !== $full) {
            return $full;
        }

        $username = $this->metaText($contact, 'username');

        return null === $username ? null : '@' . $username;
    }

    private function metaText(Contact $contact, string $key): ?string
    {
        $meta  = $contact->getAttribute('meta');
        $value = is_array($meta) ? ($meta[$key] ?? null) : null;

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $text = mb_trim((string) $value);

        return '' === $text ? null : $text;
    }

    /**
     * A relative URL of one of this screen's routes, inside the current assistant's console.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $action, array $parameters = []): string
    {
        $name = match ($action) {
            'index', 'view' => 'filament.assistant.resources.contacts.' . $action,
            default         => 'console.contacts.' . $action,
        };

        return route($name, ['tenant' => (string) $this->assistant->get()->getKey(), ...$parameters], false);
    }
}
