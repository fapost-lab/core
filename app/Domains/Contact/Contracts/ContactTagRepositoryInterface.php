<?php

declare(strict_types=1);

namespace App\Domains\Contact\Contracts;

/**
 * Persistence contract for contact tags.
 *
 * All operations are idempotent so flow node handlers remain safe to retry:
 * adding an existing tag is a no-op, removing a missing tag is a no-op.
 */
interface ContactTagRepositoryInterface
{
    /**
     * Add a tag to a contact. Upserts on the unique (contact_id, tag) pair so a
     * retried node execution never produces duplicates. Returns true when a new
     * tag row was created, false when the tag was already present.
     */
    public function add(string $contactId, string $tag, ?string $taggedBy): bool;

    /**
     * Remove a tag from a contact. No-op when the tag is absent. Returns true
     * when a row was deleted.
     */
    public function remove(string $contactId, string $tag): bool;

    /**
     * Toggle a tag: add it when absent, remove it when present. Returns the
     * resulting presence state (true = tag is now set, false = tag removed).
     */
    public function toggle(string $contactId, string $tag, ?string $taggedBy): bool;

    /**
     * Whether the contact currently carries the given tag.
     */
    public function has(string $contactId, string $tag): bool;

    /**
     * Contact ids carrying the given tag — used by segmentation/broadcasting.
     *
     * @return list<string>
     */
    public function contactIdsWithTag(string $tag): array;

    /**
     * Distinct tag strings used across the current tenant, sorted A→Z. Powers the
     * builder autocomplete for the `set_tag` node. The table lives in the tenant
     * schema, so this is already tenant-scoped.
     *
     * @return list<string>
     */
    public function distinctTags(): array;

    /**
     * Replace a contact's tag set with exactly {@code $tags} (added/removed to
     * match), recording {@code $taggedBy} on newly created rows. Used by the
     * admin UI for manual tagging. Idempotent and blank-safe.
     *
     * @param  list<string>  $tags
     */
    public function syncForContact(string $contactId, array $tags, ?string $taggedBy): void;
}
