<?php

declare(strict_types=1);

namespace App\Domains\Contact\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditChanges;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Contact\Contracts\ContactTagRepositoryInterface;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads and writes the {@see Contact}s that one assistant's console shows, which the caller names.
 *
 * A contact belongs to the tenant, not to an assistant: the assistant sees the contacts that wrote to at least one of
 * its channels (`channel_contacts` to `channels.assistant_id`). Every read is bounded by the tenant and that link,
 * explicitly, because no global scope does it implicitly: a contact of another tenant or of another
 * assistant is "not found", never "forbidden". The assistant is an argument so the controller takes it once, before a
 * write that could reset the current assistant.
 *
 * {@see \App\Domains\Contact\Policies\ContactPolicy} does not look at the assistant of the record, so no ability probe is
 * needed for the `can` flags; if the policy starts to check it, add one as {@see \App\Domains\Flow\Services\FlowDraftService} has.
 *
 * A staff write is recorded in the audit log as markers only (`tags`, `groups`) and the contact is labelled by a short
 * id: the log never holds a contact's name, phone, username, tag or attribute value.
 */
final readonly class AssistantContactService
{
    /**
     * `contacts.language` is `string(10)`; a filter value outside this is no language.
     */
    public const string LANGUAGE_PATTERN = '/^[A-Za-z0-9_-]{1,10}$/';

    public function __construct(
        private TenantContextInterface $tenants,
        private ContactTagRepositoryInterface $tags,
        private ContactPlatforms $platforms,
        private AuditTrailInterface $audit,
    ) {
    }

    /**
     * The contacts of the assistant, for a list.
     *
     * `whereHas` (an EXISTS), not a join: a contact linked to two channels of the assistant is one row, and the sort
     * columns stay unambiguous.
     *
     * @return Builder<Contact>
     */
    public function query(Assistant $assistant): Builder
    {
        return Contact::query()
            ->where('contacts.tenant_id', $this->tenantId())
            ->whereHas('channelContacts.channel', static function (Builder $channels) use ($assistant): void {
                $channels->where('assistant_id', $assistant->getKey());
            });
    }

    /**
     * @throws ModelNotFoundException when the id is not a contact of the assistant
     */
    public function findFor(Assistant $assistant, string $id): Contact
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(Contact::class, [$id]);
        }

        return $this->query($assistant)->whereKey($id)->firstOrFail();
    }

    /**
     * Narrows the list to one platform.
     *
     * @param  Builder<covariant Model>  $query
     *
     * @return bool whether it narrowed the query (a value that is no platform is no filter)
     */
    public function filterByPlatform(Builder $query, string $value): bool
    {
        if (! $this->platforms->has($value)) {
            return false;
        }

        $query->where('contacts.platform', $value);

        return true;
    }

    /**
     * Narrows the list to one language.
     *
     * @param  Builder<covariant Model>  $query
     *
     * @return bool whether it narrowed the query (a value that cannot be a language is no filter)
     */
    public function filterByLanguage(Builder $query, string $value): bool
    {
        if (1 !== preg_match(self::LANGUAGE_PATTERN, $value)) {
            return false;
        }

        $query->where('contacts.language', $value);

        return true;
    }

    /**
     * The languages of the assistant's own contacts, for the language filter.
     *
     * @return list<string>
     */
    public function languageOptions(Assistant $assistant): array
    {
        return $this->query($assistant)
            ->whereNotNull('contacts.language')
            ->where('contacts.language', '!=', '')
            ->distinct()
            ->orderBy('contacts.language')
            ->pluck('contacts.language')
            ->map(static fn (mixed $language): string => (string) $language)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function tags(Contact $contact): array
    {
        return $contact->tags()->orderBy('tag')->pluck('tag')->values()->all();
    }

    /**
     * The groups the contact is in, within the tenant.
     *
     * @return list<array{id: string, name: string}>
     */
    public function groups(Contact $contact): array
    {
        return $contact->groups()
            ->where('contact_groups.tenant_id', $this->tenantId())
            ->orderBy('name')
            ->get(['contact_groups.id', 'contact_groups.name'])
            ->map(static fn (ContactGroup $group): array => ['id' => (string) $group->getKey(), 'name' => $group->name])
            ->values()
            ->all();
    }

    /**
     * Replaces the contact's tags with exactly these. Blank and repeated tags are dropped; tags the contact already has
     * (a flow may have set them) keep their `tagged_by`.
     *
     * @param  list<string>  $tags
     */
    public function syncTags(Assistant $assistant, Contact $contact, array $tags, string $staffUserId): void
    {
        DB::transaction(function () use ($assistant, $contact, $tags, $staffUserId): void {
            $before = $this->tags($contact);

            $this->tags->syncForContact((string) $contact->getKey(), $tags, $staffUserId);

            $this->recordUpdate($assistant, $contact, ['tags' => $before], ['tags' => $this->tags($contact)], 'tags');
        });
    }

    /**
     * Replaces the contact's groups with exactly these. An id that is no group of the tenant is dropped: the request
     * validates it, this is the second line.
     *
     * @param  list<string>  $groupIds
     */
    public function syncGroups(Assistant $assistant, Contact $contact, array $groupIds): void
    {
        DB::transaction(function () use ($assistant, $contact, $groupIds): void {
            $ids = [] === $groupIds
                ? []
                : ContactGroup::query()
                    ->where('tenant_id', $this->tenantId())
                    ->whereKey($groupIds)
                    ->pluck('id')
                    ->all();

            $before = $this->groupIds($contact);

            $contact->groups()->sync($ids);

            $this->recordUpdate($assistant, $contact, ['groups' => $before], ['groups' => $this->groupIds($contact)], 'groups');
        });
    }

    /**
     * Every tag in use in the tenant, for the tag input's suggestions.
     *
     * @return list<string>
     */
    public function tagSuggestions(): array
    {
        return $this->tags->distinctTags();
    }

    /**
     * @return list<string>
     */
    private function groupIds(Contact $contact): array
    {
        $ids = $contact->groups()->pluck('contact_groups.id')->map(static fn (mixed $id): string => (string) $id)->all();
        sort($ids);

        return array_values($ids);
    }

    /**
     * Records a card write as a marker, when it changed anything. The label is a short id, never the contact's name.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function recordUpdate(Assistant $assistant, Contact $contact, array $before, array $after, string $marker): void
    {
        $changes = AuditChanges::between($before, $after, [], [$marker]);

        if ($changes->isEmpty()) {
            return;
        }

        $this->audit->record(new AuditRecord(
            action: AuditAction::ContactUpdated,
            subject: $contact,
            // The tail of the ULID is its random part (the head is the time), so it tells contacts apart.
            subjectLabel: 'Contact · ' . mb_substr((string) $contact->getKey(), -8),
            assistantId: (string) $assistant->getKey(),
            changes: $changes,
        ));
    }

    private function tenantId(): string
    {
        return $this->tenants->get()->getId();
    }
}
