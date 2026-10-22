<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Services;

use App\Domains\Conversation\Contracts\ConversationOwnershipInterface;
use App\Domains\Conversation\DTO\ConversationRef;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Eloquent-backed thread ownership over the tenant's operational database.
 *
 * Reads the same columns the logging migration reserved for the Inbox
 * (`owner_type`, `owner_staff_user_id`) plus `queued_at`, and the unread counter
 * the capture pipeline increments. Every write is one conditional UPDATE, so
 * the database decides a race, not the application.
 */
final readonly class ConversationOwnership implements ConversationOwnershipInterface
{
    public function isHandledByStaff(ConversationRef $ref): bool
    {
        return Conversation::query()
            ->where('tenant_id', $ref->tenantId)
            ->where('assistant_id', $ref->assistantId)
            ->where('contact_id', $ref->contactId)
            ->where('channel_id', $ref->channelId)
            ->whereIn('owner_type', [ConversationOwner::Staff->value, ConversationOwner::Queued->value])
            ->exists();
    }

    public function takeOver(string $conversationId, string $staffUserId): bool
    {
        return Conversation::query()
            ->whereKey($conversationId)
            ->where(static fn (Builder $query) => $query
                ->whereNull('owner_type')
                ->orWhereIn('owner_type', [ConversationOwner::Bot->value, ConversationOwner::Queued->value]))
            ->update([
                'owner_type'          => ConversationOwner::Staff->value,
                'owner_staff_user_id' => $staffUserId,
                'queued_at'           => null,
                'updated_at'          => Carbon::now(),
            ]) > 0;
    }

    public function finish(string $conversationId, string $staffUserId): bool
    {
        return $this->heldBy($conversationId, $staffUserId)
            ->update([
                'owner_type'          => ConversationOwner::Bot->value,
                'owner_staff_user_id' => null,
                'queued_at'           => null,
                'updated_at'          => Carbon::now(),
            ]) > 0;
    }

    public function transfer(string $conversationId, string $fromUserId, string $toUserId): bool
    {
        return $this->heldBy($conversationId, $fromUserId)
            ->update([
                'owner_staff_user_id' => $toUserId,
                'updated_at'          => Carbon::now(),
            ]) > 0;
    }

    public function seize(string $conversationId, string $expectedOwnerId, string $adminUserId): bool
    {
        return $this->heldBy($conversationId, $expectedOwnerId)
            ->update([
                'owner_staff_user_id' => $adminUserId,
                'updated_at'          => Carbon::now(),
            ]) > 0;
    }

    public function markRead(string $conversationId): void
    {
        Conversation::query()
            ->whereKey($conversationId)
            ->where('unread_count', '>', 0)
            ->update([
                'unread_count' => 0,
                'updated_at'   => Carbon::now(),
            ]);
    }

    /**
     * The thread, but only while this operator holds it.
     *
     * @return Builder<Conversation>
     */
    private function heldBy(string $conversationId, string $staffUserId): Builder
    {
        return Conversation::query()
            ->whereKey($conversationId)
            ->where('owner_type', ConversationOwner::Staff->value)
            ->where('owner_staff_user_id', $staffUserId);
    }
}
