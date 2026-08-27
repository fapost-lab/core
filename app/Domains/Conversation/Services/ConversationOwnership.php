<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Services;

use App\Domains\Conversation\Contracts\ConversationOwnershipInterface;
use App\Domains\Conversation\DTO\ConversationRef;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Models\Conversation;
use Illuminate\Support\Carbon;

/**
 * Eloquent-backed thread ownership over the tenant's operational database.
 *
 * Reads the same columns the logging migration reserved for the Inbox
 * (`owner_type`, `owner_staff_user_id`) and the unread counter the capture
 * pipeline increments.
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
            ->where('owner_type', ConversationOwner::Staff->value)
            ->exists();
    }

    public function assign(string $conversationId, ConversationOwner $owner, ?string $staffUserId = null): void
    {
        Conversation::query()
            ->whereKey($conversationId)
            ->update([
                // Returning a thread to the bot must also drop the operator, or
                // the inbox would keep showing it as someone's assignment.
                'owner_type'          => $owner->value,
                'owner_staff_user_id' => ConversationOwner::Staff === $owner ? $staffUserId : null,
                'updated_at'          => Carbon::now(),
            ]);
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
}
