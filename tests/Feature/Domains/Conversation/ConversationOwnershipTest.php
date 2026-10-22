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
use Illuminate\Support\Carbon;
use Tests\Feature\FeatureTestCase;

/**
 * Covers {@see \App\Domains\Conversation\Services\ConversationOwnership} — the
 * conditional takeover / finish / transfer / seize writes behind the inbox header actions, and the unread-counter reset used on thread open.
 */
final class ConversationOwnershipTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string STAFF_ID = '00000000-0000-0000-0000-0000000000aa';

    private const string OTHER_STAFF_ID = '00000000-0000-0000-0000-0000000000ab';

    private const string ADMIN_ID = '00000000-0000-0000-0000-0000000000ac';

    public function test_take_over_accepts_a_bot_or_queued_thread_and_clears_the_queue_mark(): void
    {
        $ownership = app(ConversationOwnershipInterface::class);

        $fresh = $this->createConversation();
        $bot   = $this->createConversation(['owner_type' => ConversationOwner::Bot]);
        $queue = $this->createConversation(['owner_type' => ConversationOwner::Queued, 'queued_at' => Carbon::now()]);

        foreach ([$fresh, $bot, $queue] as $conversation) {
            $this->assertTrue($ownership->takeOver((string) $conversation->getKey(), self::STAFF_ID));

            $reloaded = $conversation->fresh();
            $this->assertSame(ConversationOwner::Staff, $reloaded->owner_type);
            $this->assertSame(self::STAFF_ID, $reloaded->owner_staff_user_id);
            $this->assertNull($reloaded->queued_at);
        }
    }

    public function test_only_one_of_two_operators_wins_the_take_over(): void
    {
        $conversation = $this->createConversation(['owner_type' => ConversationOwner::Queued, 'queued_at' => Carbon::now()]);
        $ownership    = app(ConversationOwnershipInterface::class);

        $this->assertTrue($ownership->takeOver((string) $conversation->getKey(), self::STAFF_ID));
        $this->assertFalse($ownership->takeOver((string) $conversation->getKey(), self::OTHER_STAFF_ID));

        $this->assertSame(self::STAFF_ID, $conversation->fresh()->owner_staff_user_id);
    }

    public function test_finish_returns_the_thread_to_the_bot_only_for_its_holder(): void
    {
        $conversation = $this->createConversation(['owner_type' => ConversationOwner::Staff, 'owner_staff_user_id' => self::STAFF_ID]);
        $ownership    = app(ConversationOwnershipInterface::class);

        $this->assertFalse($ownership->finish((string) $conversation->getKey(), self::OTHER_STAFF_ID));
        $this->assertSame(ConversationOwner::Staff, $conversation->fresh()->owner_type);

        $this->assertTrue($ownership->finish((string) $conversation->getKey(), self::STAFF_ID));

        $fresh = $conversation->fresh();
        $this->assertSame(ConversationOwner::Bot, $fresh->owner_type);
        $this->assertNull($fresh->owner_staff_user_id);

        $queued = $this->createConversation(['owner_type' => ConversationOwner::Queued, 'queued_at' => Carbon::now()]);
        $this->assertFalse($ownership->finish((string) $queued->getKey(), self::STAFF_ID));
    }

    public function test_transfer_and_seize_move_the_thread_only_from_the_expected_holder(): void
    {
        $conversation = $this->createConversation(['owner_type' => ConversationOwner::Staff, 'owner_staff_user_id' => self::STAFF_ID]);
        $id           = (string) $conversation->getKey();
        $ownership    = app(ConversationOwnershipInterface::class);

        $this->assertFalse($ownership->transfer($id, self::OTHER_STAFF_ID, self::ADMIN_ID));
        $this->assertFalse($ownership->seize($id, self::OTHER_STAFF_ID, self::ADMIN_ID));
        $this->assertSame(self::STAFF_ID, $conversation->fresh()->owner_staff_user_id);

        $this->assertTrue($ownership->transfer($id, self::STAFF_ID, self::OTHER_STAFF_ID));
        $this->assertSame(self::OTHER_STAFF_ID, $conversation->fresh()->owner_staff_user_id);

        $this->assertTrue($ownership->seize($id, self::OTHER_STAFF_ID, self::ADMIN_ID));
        $this->assertSame(self::ADMIN_ID, $conversation->fresh()->owner_staff_user_id);
        $this->assertSame(ConversationOwner::Staff, $conversation->fresh()->owner_type);

        $bot = $this->createConversation();
        $this->assertFalse($ownership->transfer((string) $bot->getKey(), self::STAFF_ID, self::OTHER_STAFF_ID));
        $this->assertFalse($ownership->seize((string) $bot->getKey(), self::STAFF_ID, self::ADMIN_ID));
    }

    public function test_is_handled_by_staff_is_true_for_a_held_or_a_queued_thread_and_false_for_the_bot(): void
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

        $conversation->forceFill(['owner_type' => ConversationOwner::Queued, 'queued_at' => Carbon::now()])->save();
        $this->assertTrue($ownership->isHandledByStaff($ref));

        $conversation->forceFill(['owner_type' => ConversationOwner::Staff, 'owner_staff_user_id' => self::STAFF_ID])->save();
        $this->assertTrue($ownership->isHandledByStaff($ref));

        $conversation->forceFill(['owner_type' => ConversationOwner::Bot, 'owner_staff_user_id' => null])->save();
        $this->assertFalse($ownership->isHandledByStaff($ref));
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

        $conversation->forceFill(['owner_type' => ConversationOwner::Staff, 'owner_staff_user_id' => self::STAFF_ID])->save();

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
