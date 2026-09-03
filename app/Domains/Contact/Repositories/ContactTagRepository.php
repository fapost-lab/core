<?php

declare(strict_types=1);

namespace App\Domains\Contact\Repositories;

use App\Domains\Contact\Contracts\ContactTagRepositoryInterface;
use App\Domains\Contact\Models\ContactTag;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Eloquent-backed contact tag store.
 *
 * Reads/writes the {@code contact_tags} table. Idempotency is guaranteed by the
 * unique (contact_id, tag) constraint plus violation fallbacks, keeping the
 * {@see \App\Domains\Flow\Handlers\SetTagNodeHandler} safe to retry.
 */
final class ContactTagRepository implements ContactTagRepositoryInterface
{
    /**
     * The insert runs in its own (nested) transaction: on PostgreSQL a failed
     * statement aborts the whole surrounding transaction, so catching the
     * violation without a savepoint to roll back to would leave every later
     * query of the caller's transaction failing.
     */
    public function add(string $contactId, string $tag, ?string $taggedBy): bool
    {
        $query = ContactTag::query();

        try {
            $query->getConnection()->transaction(static function () use ($query, $contactId, $tag, $taggedBy): void {
                $query->create([
                    'contact_id' => $contactId,
                    'tag'        => $tag,
                    'tagged_by'  => $taggedBy,
                    'tagged_at'  => now(),
                ]);
            });

            return true;
        } catch (UniqueConstraintViolationException) {
            // Tag already present — retry-safe no-op.
            return false;
        }
    }

    public function remove(string $contactId, string $tag): bool
    {
        return ContactTag::query()
            ->where('contact_id', $contactId)
            ->where('tag', $tag)
            ->delete() > 0;
    }

    public function toggle(string $contactId, string $tag, ?string $taggedBy): bool
    {
        if ($this->has($contactId, $tag)) {
            $this->remove($contactId, $tag);

            return false;
        }

        $this->add($contactId, $tag, $taggedBy);

        return true;
    }

    public function has(string $contactId, string $tag): bool
    {
        return ContactTag::query()
            ->where('contact_id', $contactId)
            ->where('tag', $tag)
            ->exists();
    }

    public function contactIdsWithTag(string $tag): array
    {
        return ContactTag::query()
            ->where('tag', $tag)
            ->pluck('contact_id')
            ->all();
    }

    public function distinctTags(): array
    {
        return ContactTag::query()
            ->select('tag')
            ->distinct()
            ->orderBy('tag')
            ->pluck('tag')
            ->all();
    }

    public function syncForContact(string $contactId, array $tags, ?string $taggedBy): void
    {
        $desired = array_values(array_unique(array_filter(
            array_map(static fn (string $tag): string => mb_trim($tag), $tags),
            static fn (string $tag): bool => '' !== $tag,
        )));

        $existing = ContactTag::query()
            ->where('contact_id', $contactId)
            ->pluck('tag')
            ->all();

        foreach (array_diff($desired, $existing) as $tag) {
            $this->add($contactId, $tag, $taggedBy);
        }

        $toRemove = array_diff($existing, $desired);

        if ([] !== $toRemove) {
            ContactTag::query()
                ->where('contact_id', $contactId)
                ->whereIn('tag', $toRemove)
                ->delete();
        }
    }
}
