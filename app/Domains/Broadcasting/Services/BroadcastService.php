<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Services;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Enums\BroadcastTarget;
use App\Domains\Broadcasting\Exceptions\BroadcastAlreadyStartedException;
use App\Domains\Broadcasting\Exceptions\BroadcastChangedException;
use App\Domains\Broadcasting\Exceptions\BroadcastNotEditableException;
use App\Domains\Broadcasting\Exceptions\BroadcastNotSendableException;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Support\BroadcastMessage;
use App\Domains\Contact\Contracts\ContactTagRepositoryInterface;
use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Settings\TenantSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes and reads the {@see Broadcast}s of the current assistant on the console.
 *
 * Every read and write is bounded by the tenant and the assistant, explicitly, because the console does not register
 * Filament's tenancy scope: a broadcast of another assistant or tenant is "not found", never "forbidden".
 *
 * A broadcast reaches real people, so the three things that can go wrong are closed here and not left to the screen:
 *
 *  - it is changed only while it is a draft ({@see update()}): a started run reads the message for each recipient as it
 *    delivers, so a later edit would change what the rest of the audience receives;
 *  - it is started only on the revision a person confirmed ({@see send()}), so what is sent is what was shown;
 *  - it is started once: {@see BroadcastDispatcher::start()} is a single conditional UPDATE, and only its winner
 *    queues the run.
 */
final readonly class BroadcastService
{
    public function __construct(
        private CurrentAssistantInterface $assistant,
        private TenantContextInterface $tenants,
        private TenantSettings $settings,
        private BroadcastDispatcher $dispatcher,
        private ContactTagRepositoryInterface $tags,
    ) {
    }

    /**
     * The broadcasts of the current assistant.
     *
     * @return Builder<Broadcast>
     */
    public function query(): Builder
    {
        return Broadcast::query()
            ->where('broadcasts.tenant_id', $this->tenants->get()->getId())
            ->where('broadcasts.assistant_id', (string) $this->assistant->get()->getKey());
    }

    /**
     * @throws ModelNotFoundException when the id is not a broadcast of the current assistant
     */
    public function findForAssistant(string $id): Broadcast
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(Broadcast::class, [$id]);
        }

        return $this->query()->with('assistant')->whereKey($id)->firstOrFail();
    }

    /**
     * An unsaved broadcast of the current assistant, to ask the policy about abilities that need a record when only
     * the screen's own answer is wanted. The policy looks at the record's assistant, so it is set.
     */
    public function abilityProbe(): Broadcast
    {
        return Broadcast::query()->getModel()->newInstance()->setRelation('assistant', $this->assistant->get());
    }

    /**
     * Creates a draft for the current assistant.
     *
     * @param  array{name: string, message?: mixed, target_type: string, target_tags?: list<string>|null, target_segment_id?: string|null}  $fields
     */
    public function create(array $fields, ?string $userId): Broadcast
    {
        return Broadcast::query()->create([
            ...$this->canonical($fields),
            'tenant_id'    => $this->tenants->get()->getId(),
            'assistant_id' => (string) $this->assistant->get()->getKey(),
            'created_by'   => $userId,
            'status'       => BroadcastStatus::Draft->value,
        ]);
    }

    /**
     * Changes a draft. The row is locked and the write carries the status in its WHERE, so a broadcast that was
     * started a moment ago is not touched: the lock orders this against the start on PostgreSQL, the condition is
     * what still holds where locks are not taken.
     *
     * @param  array{name: string, message?: mixed, target_type: string, target_tags?: list<string>|null, target_segment_id?: string|null}  $fields
     *
     * @throws BroadcastNotEditableException when the broadcast is not a draft (any more)
     */
    public function update(Broadcast $broadcast, array $fields): void
    {
        $canonical = $this->canonical($fields);

        $updated = DB::transaction(function () use ($broadcast, $canonical): int {
            if (null === $this->query()->whereKey($broadcast->getKey())->lockForUpdate()->first()) {
                return 0;
            }

            // The query builder does not apply the model's casts, so the JSON columns are encoded here.
            return $this->query()
                ->whereKey($broadcast->getKey())
                ->where('status', BroadcastStatus::Draft->value)
                ->update([
                    'name'              => $canonical['name'],
                    'message'           => null === $canonical['message'] ? null : json_encode($canonical['message'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'target_type'       => $canonical['target_type'],
                    'target_tags'       => null === $canonical['target_tags'] ? null : json_encode($canonical['target_tags'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'target_segment_id' => $canonical['target_segment_id'],
                ]);
        });

        if (1 !== $updated) {
            throw new BroadcastNotEditableException((string) $broadcast->getKey());
        }
    }

    /**
     * Starts a draft, on the revision the person confirmed.
     *
     * Inside one transaction the row is locked and read again, and the send goes ahead only if the broadcast is still
     * a draft, still has the revision that was shown, has text in the base language and points at an audience that
     * exists. Then {@see BroadcastDispatcher::start()} decides who wins; it queues the run once the transaction has
     * committed.
     *
     * @throws BroadcastAlreadyStartedException when the broadcast is not a draft, or lost the race to start
     * @throws BroadcastChangedException        when the draft was edited since the revision was shown
     * @throws BroadcastNotSendableException    when the base language has no text, or the audience is gone
     */
    public function send(Broadcast $broadcast, string $revision): void
    {
        DB::transaction(function () use ($broadcast, $revision): void {
            $locked = $this->query()->whereKey($broadcast->getKey())->lockForUpdate()->firstOrFail();
            $id     = (string) $locked->getKey();

            if (BroadcastStatus::Draft !== $locked->status) {
                throw new BroadcastAlreadyStartedException($id);
            }

            if (! hash_equals($this->revision($locked), $revision)) {
                throw new BroadcastChangedException($id);
            }

            if (! BroadcastMessage::hasBaseLanguageText($locked->message, $this->settings->content_base_language)) {
                throw new BroadcastNotSendableException($id, BroadcastNotSendableException::BASE_LANGUAGE);
            }

            if (! $this->audienceExists($locked)) {
                throw new BroadcastNotSendableException($id, BroadcastNotSendableException::AUDIENCE);
            }

            if (! $this->dispatcher->start($locked)) {
                throw new BroadcastAlreadyStartedException($id);
            }
        });
    }

    /**
     * Stops a running broadcast: the remaining recipients are skipped, what was delivered stays delivered.
     *
     * @return bool false when the broadcast was not running (it finished, or was cancelled already)
     */
    public function cancel(Broadcast $broadcast): bool
    {
        return $this->query()
            ->whereKey($broadcast->getKey())
            ->where('status', BroadcastStatus::Running->value)
            ->update([
                'status'       => BroadcastStatus::Cancelled->value,
                'completed_at' => Carbon::now(),
            ]) > 0;
    }

    /**
     * Deletes a broadcast that is not running, with its recipients (the foreign key cascades). One DELETE with the
     * status in its WHERE, so a broadcast started a moment ago is not removed from under its run. It is a query
     * delete: model events do not fire, and the broadcast has no observers.
     *
     * @return bool false when nothing was deleted: the broadcast is running (or already gone)
     */
    public function delete(Broadcast $broadcast): bool
    {
        return $this->query()
            ->whereKey($broadcast->getKey())
            ->where('status', '!=', BroadcastStatus::Running->value)
            ->delete() > 0;
    }

    /**
     * What a person confirms when they send: a hash of everything that decides what goes out and to whom. It is of the
     * content, not of `updated_at`, which has a one-second resolution and cannot tell two edits in a second apart. Keys
     * are sorted because `jsonb` does not keep their order.
     */
    public function revision(Broadcast $broadcast): string
    {
        $message = is_array($broadcast->message) ? $broadcast->message : null;

        if (null !== $message) {
            ksort($message);
        }

        $tags = is_array($broadcast->target_tags) ? array_values($broadcast->target_tags) : null;

        if (null !== $tags) {
            sort($tags);
        }

        return hash('sha256', json_encode([
            'name'              => $broadcast->name,
            'message'           => $message,
            'target_type'       => $broadcast->target_type->value,
            'target_tags'       => $tags,
            'target_segment_id' => $broadcast->target_segment_id,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * The tenant's segments, by name, for the audience picker.
     *
     * @return list<array{value: string, label: string}>
     */
    public function segmentOptions(): array
    {
        return ContactSegment::query()
            ->where('tenant_id', $this->tenants->get()->getId())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (ContactSegment $segment): array => ['value' => (string) $segment->getKey(), 'label' => $segment->name])
            ->values()
            ->all();
    }

    /**
     * Whether the segment is one of the current tenant's.
     */
    public function segmentExists(string $id): bool
    {
        return Str::isUuid($id) && ContactSegment::query()
            ->where('tenant_id', $this->tenants->get()->getId())
            ->whereKey($id)
            ->exists();
    }

    /**
     * The tags contacts carry, for the audience picker.
     *
     * @return list<string>
     */
    public function tagOptions(): array
    {
        return array_values($this->tags->distinctTags());
    }

    /**
     * The languages the message has a tab for: the base language first (it is required), then the tenant's enabled
     * languages, then English, then any language the saved message already has text in, so an edit never drops text.
     *
     * @param  array<array-key, mixed>|null  $message
     *
     * @return list<string>
     */
    public function languages(?array $message = null): array
    {
        $languages = [
            $this->settings->content_base_language,
            ...$this->settings->available_languages,
            'en',
            ...array_keys($message ?? []),
        ];

        return array_values(array_unique(array_filter($languages, 'is_string')));
    }

    public function baseLanguage(): string
    {
        return $this->settings->content_base_language;
    }

    /**
     * The fields as they are stored: the message cleaned, and only the fields the chosen target uses (a draft that was
     * once "by tags" does not keep its tags after it is switched to "all contacts").
     *
     * @param  array{name: string, message?: mixed, target_type: string, target_tags?: list<string>|null, target_segment_id?: string|null}  $fields
     *
     * @return array{name: string, message: array<string, string>|null, target_type: string, target_tags: list<string>|null, target_segment_id: string|null}
     */
    private function canonical(array $fields): array
    {
        $target = BroadcastTarget::from($fields['target_type']);

        return [
            'name'              => $fields['name'],
            'message'           => BroadcastMessage::clean($fields['message'] ?? null),
            'target_type'       => $target->value,
            'target_tags'       => BroadcastTarget::Tags === $target ? array_values($fields['target_tags'] ?? []) : null,
            'target_segment_id' => BroadcastTarget::Segment === $target ? ($fields['target_segment_id'] ?? null) : null,
        ];
    }

    /**
     * Whether what the audience names still exists: the segment is the tenant's, the tags are on some contact.
     */
    private function audienceExists(Broadcast $broadcast): bool
    {
        return match ($broadcast->target_type) {
            BroadcastTarget::All     => true,
            BroadcastTarget::Segment => null !== $broadcast->target_segment_id && $this->segmentExists($broadcast->target_segment_id),
            BroadcastTarget::Tags    => [] !== ($broadcast->target_tags ?? [])
                && [] === array_diff($broadcast->target_tags ?? [], $this->tagOptions()),
        };
    }
}
