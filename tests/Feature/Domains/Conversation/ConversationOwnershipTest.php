<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Conversation;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Contracts\ConversationOwnershipInterface;
use App\Domains\Conversation\DTO\ConversationRef;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Models\Conversation;
use Tests\Feature\FeatureTestCase;

/**
 * Covers {@see \App\Domains\Conversation\Services\ConversationOwnership} — the
 * takeover / return-to-bot toggle used by the inbox "Take over" / "Return to
 * bot" header actions, and the unread-counter reset used on thread open.
 */
final class ConversationOwnershipTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string STAFF_ID = '00000000-0000-0000-0000-0000000000aa';

    public function test_assign_staff_sets_owner_type_and_staff_user_id(): void
    {
        $conversation = $this->createConversation();
        $ownership    = app(ConversationOwnershipInterface::class);

        $ownership->assign((string) $conversation->getKey(), ConversationOwner::Staff, self::STAFF_ID);

        $fresh = $conversation->fresh();
        $this->assertSame(ConversationOwner::Staff, $fresh->owner_type);
        $this->assertSame(self::STAFF_ID, $fresh->owner_staff_user_id);
    }

    public function test_assign_bot_clears_owner_staff_user_id(): void
    {
        $conversation = $this->createConversation();
        $ownership    = app(ConversationOwnershipInterface::class);

        $ownership->assign((string) $conversation->getKey(), ConversationOwner::Staff, self::STAFF_ID);
        $ownership->assign((string) $conversation->getKey(), ConversationOwner::Bot);

        $fresh = $conversation->fresh();
        $this->assertSame(ConversationOwner::Bot, $fresh->owner_type);
        $this->assertNull($fresh->owner_staff_user_id);
    }

    public function test_is_handled_by_staff_reflects_current_owner(): void
    {
        $conversation = $this->createConversation();
        $ownership    = app(ConversationOwnershipInterface::class);

        $ref = new ConversationRef(
            tenantId: (string) $conversation->tenant_id,
            assistantId: (string) $conversation->assistant_id,
            contactId: (string) $conversation->contact_id,
            channelId: (string) $conversation->channel_id,
            platform: $conversation->platform,
        );

        $this->assertFalse($ownership->isHandledByStaff($ref));

        $ownership->assign((string) $conversation->getKey(), ConversationOwner::Staff, self::STAFF_ID);

        $this->assertTrue($ownership->isHandledByStaff($ref));
    }

    /**
     * A contact's very first message arrives before any {@see Conversation}
     * thread row exists yet — {@see \App\Domains\Conversation\Services\ConversationOwnership::isHandledByStaff()}
     * must resolve to false rather than throw, so the flow engine answers as
     * usual instead of routing the message nowhere.
     */
    public function test_is_handled_by_staff_returns_false_when_no_thread_exists_yet(): void
    {
        $ownership = app(ConversationOwnershipInterface::class);

        $ref = new ConversationRef(
            tenantId: self::TENANT_ID,
            assistantId: (string) Assistant::factory()->create(['tenant_id' => self::TENANT_ID])->getKey(),
            contactId: (string) Contact::factory()->forTenant(self::TENANT_ID)->create()->getKey(),
            channelId: '00000000-0000-0000-0000-0000000000ff',
            platform: 'telegram',
        );

        $this->assertFalse($ownership->isHandledByStaff($ref));
    }

    /**
     * Thread identity is the full (tenant, assistant, contact, channel)
     * tuple — a staff-owned thread on one channel must not leak "handled by
     * staff" to a lookup for the same contact on a different channel.
     */
    public function test_is_handled_by_staff_is_false_for_a_different_channel(): void
    {
        $conversation = $this->createConversation();
        $ownership    = app(ConversationOwnershipInterface::class);

        $ownership->assign((string) $conversation->getKey(), ConversationOwner::Staff, self::STAFF_ID);

        $otherChannelRef = new ConversationRef(
            tenantId: (string) $conversation->tenant_id,
            assistantId: (string) $conversation->assistant_id,
            contactId: (string) $conversation->contact_id,
            channelId: '00000000-0000-0000-0000-0000000000fe',
            platform: $conversation->platform,
        );

        $this->assertFalse($ownership->isHandledByStaff($otherChannelRef));
    }

    public function test_mark_read_clears_unread_counter(): void
    {
        $conversation = $this->createConversation(['unread_count' => 3]);
        $ownership    = app(ConversationOwnershipInterface::class);

        $ownership->markRead((string) $conversation->getKey());

        $this->assertSame(0, $conversation->fresh()->unread_count);
    }

    public function test_mark_read_is_a_no_op_when_already_zero(): void
    {
        $conversation = $this->createConversation(['unread_count' => 0]);
        $ownership    = app(ConversationOwnershipInterface::class);

        $ownership->markRead((string) $conversation->getKey());

        $this->assertSame(0, $conversation->fresh()->unread_count);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createConversation(array $overrides = []): Conversation
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
        ]));

        return Conversation::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $assistant->getKey(),
            'contact_id'   => $contact->getKey(),
            'channel_id'   => $channel->getKey(),
            'platform'     => 'telegram',
            ...$overrides,
        ]);
    }
}
