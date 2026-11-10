<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Conversation;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Enums\DeliveryStatus;
use App\Domains\Conversation\Enums\MessageContentType;
use App\Domains\Conversation\Enums\MessageDirection;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Conversation\Enums\MessageSenderType;
use App\Domains\Conversation\Jobs\PersistConversationMessageJob;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\ConversationMessage;
use App\Domains\Conversation\Notifications\ConversationOperatorNotification;
use App\Domains\Conversation\Services\ConversationInbox;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Domains\Staff\Services\StaffNotificationInbox;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * What reaches an operator's bell about the threads that are theirs: a contact's message in a thread they hold (one
 * unread per thread), a thread handed to them, a thread an administrator took from them — each linking to the thread.
 */
final class ConversationOperatorNotificationTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Assistant $assistant;

    private Contact $contact;

    private Channel $channel;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());

        $this->assistant = Assistant::factory()->create();
        $this->contact   = Contact::factory()->forTenant(self::TENANT_ID)->create(['meta' => ['first_name' => 'Ada', 'last_name' => 'Lovelace']]);
        $this->channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $this->assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
        ]));
        $this->operator = $this->operatorUser();
    }

    public function test_the_holder_hears_of_a_contacts_message_once_per_thread_until_it_is_read(): void
    {
        $thread = $this->thread(ConversationOwner::Staff, $this->operator);

        $this->persist(MessageDirection::Inbound);
        $this->persist(MessageDirection::Inbound);

        $notification = $this->operator->unreadNotifications()->sole();
        $this->assertSame(ConversationOperatorNotification::class, $notification->type);
        $this->assertSame(ConversationOperatorNotification::MESSAGE, $notification->data['kind']);
        $this->assertSame((string) $thread->getKey(), $notification->data['conversation_id']);
        $this->assertSame('New message from Ada Lovelace', $notification->data['title']);
        $this->assertSame($this->threadPath($thread), $notification->data['action']['url']);

        $notification->markAsRead();
        $this->persist(MessageDirection::Inbound);

        $this->assertSame(1, $this->operator->unreadNotifications()->count());
        $this->assertSame(2, $this->operator->notifications()->count());
    }

    public function test_the_bell_lists_it_with_a_link_to_the_thread_in_the_language_of_the_page(): void
    {
        $thread = $this->thread(ConversationOwner::Staff, $this->operator);
        $this->persist(MessageDirection::Inbound);

        app()->setLocale('ru');
        $item = $this->app->make(StaffNotificationInbox::class)->latest($this->operator)[0];

        $this->assertSame('Новое сообщение от Ada Lovelace', $item['title']);
        $this->assertSame(['label' => 'Открыть диалог', 'url' => $this->threadPath($thread)], $item['action']);
    }

    public function test_nobody_hears_of_messages_the_operator_does_not_hold_or_sends(): void
    {
        $this->thread(ConversationOwner::Bot, null);
        $this->persist(MessageDirection::Inbound);

        Conversation::query()->update(['owner_type' => ConversationOwner::Queued->value, 'queued_at' => Carbon::now()]);
        $this->persist(MessageDirection::Inbound);

        Conversation::query()->update(['owner_type' => ConversationOwner::Staff->value, 'owner_staff_user_id' => $this->operator->getKey(), 'queued_at' => null]);
        $this->persist(MessageDirection::Outbound);

        $this->assertSame(0, $this->operator->notifications()->count());
    }

    public function test_a_retried_job_records_nothing_new_and_tells_nobody(): void
    {
        $this->thread(ConversationOwner::Staff, $this->operator);
        $entry = $this->entry(MessageDirection::Inbound);

        $this->app->call([new PersistConversationMessageJob($entry), 'handle']);
        $this->operator->unreadNotifications()->update(['read_at' => Carbon::now()]);
        $this->app->call([new PersistConversationMessageJob($entry), 'handle']);

        $this->assertSame(1, $this->operator->notifications()->count());
    }

    public function test_a_holder_who_may_not_use_the_console_hears_nothing(): void
    {
        $this->thread(ConversationOwner::Staff, $this->operator);
        $this->operator->forceFill(['is_active' => false])->save();

        $this->persist(MessageDirection::Inbound);

        $this->assertSame(0, $this->operator->notifications()->count());
    }

    public function test_the_platform_support_user_holding_a_thread_hears_nothing(): void
    {
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();
        $this->thread(ConversationOwner::Staff, $support);

        $this->persist(MessageDirection::Inbound);

        $this->assertSame(0, $support->notifications()->count());
    }

    public function test_a_failing_notification_is_reported_and_the_message_is_still_recorded(): void
    {
        Exceptions::fake();
        $this->thread(ConversationOwner::Staff, $this->operator);
        Event::listen(NotificationSending::class, static function (): never {
            throw new RuntimeException('The notifications table is gone');
        });

        $entry = $this->entry(MessageDirection::Inbound);
        $this->app->call([new PersistConversationMessageJob($entry), 'handle']);

        $this->assertTrue(ConversationMessage::query()->where('idempotency_key', $entry->idempotencyKey)->exists());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_the_operator_a_thread_is_transferred_to_hears_who_handed_it_over(): void
    {
        $colleague = $this->operatorUser(['name' => 'Grace']);
        $thread    = $this->thread(ConversationOwner::Staff, $colleague);

        $this->assertTrue($this->app->make(ConversationInbox::class)->transfer($this->assistant, $thread, (string) $colleague->getKey(), (string) $this->operator->getKey()));

        $notification = $this->operator->unreadNotifications()->sole();
        $this->assertSame(ConversationOperatorNotification::TRANSFERRED, $notification->data['kind']);
        $this->assertSame('Grace handed you the conversation with Ada Lovelace', $notification->data['title']);
        $this->assertSame($this->threadPath($thread), $notification->data['action']['url']);
        $this->assertSame(0, $colleague->notifications()->count());
    }

    public function test_the_operator_an_administrator_seizes_a_thread_from_hears_who_took_it(): void
    {
        $admin = User::factory()->create(['name' => 'Root']);
        $admin->assignRole(RoleEnum::Admin->value);
        $thread = $this->thread(ConversationOwner::Staff, $this->operator);

        $this->assertTrue($this->app->make(ConversationInbox::class)->seize($thread, (string) $this->operator->getKey(), $admin));

        $notification = $this->operator->unreadNotifications()->sole();
        $this->assertSame(ConversationOperatorNotification::SEIZED, $notification->data['kind']);
        $this->assertSame('Root took over your conversation with Ada Lovelace', $notification->data['title']);
        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_a_transfer_or_seizure_that_lost_tells_nobody(): void
    {
        $colleague = $this->operatorUser();
        $admin     = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);
        $thread = $this->thread(ConversationOwner::Staff, $colleague);
        $inbox  = $this->app->make(ConversationInbox::class);

        // Not the holder, and a holder the administrator did not see.
        $this->assertFalse($inbox->transfer($this->assistant, $thread, (string) $this->operator->getKey(), (string) $colleague->getKey()));
        $this->assertFalse($inbox->seize($thread, (string) $this->operator->getKey(), $admin));

        $this->assertSame(0, DatabaseNotification::query()->count());
    }

    private function persist(MessageDirection $direction): void
    {
        $this->app->call([new PersistConversationMessageJob($this->entry($direction)), 'handle']);
    }

    private function thread(ConversationOwner $owner, ?User $holder): Conversation
    {
        return Conversation::query()->create([
            'tenant_id'           => self::TENANT_ID,
            'assistant_id'        => $this->assistant->getKey(),
            'contact_id'          => $this->contact->getKey(),
            'channel_id'          => $this->channel->getKey(),
            'platform'            => 'telegram',
            'owner_type'          => $owner,
            'owner_staff_user_id' => $holder?->getKey(),
        ]);
    }

    private function threadPath(Conversation $thread): string
    {
        return route('console.conversations.show', ['assistant' => (string) $this->assistant->getKey(), 'record' => (string) $thread->getKey()], false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function operatorUser(array $attributes = []): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'operator', 'guard_name' => 'web'],
            ['display_name' => 'Operator', 'priority' => 10, 'is_system' => false],
        );
        $role->givePermissionTo([Permission::ViewConversations->value, Permission::ReplyConversations->value]);

        $user = User::factory()->create($attributes);
        $user->assignRole($role);
        $user->assistants()->attach($this->assistant);

        return $user;
    }

    private function entry(MessageDirection $direction): MessageLogEntry
    {
        return new MessageLogEntry(
            tenantId: self::TENANT_ID,
            assistantId: (string) $this->assistant->getKey(),
            contactId: (string) $this->contact->getKey(),
            channelId: (string) $this->channel->getKey(),
            platform: 'telegram',
            direction: $direction,
            senderType: MessageDirection::Inbound === $direction ? MessageSenderType::Contact : MessageSenderType::Assistant,
            contentType: MessageContentType::Text,
            text: 'hello',
            payload: [],
            media: [],
            providerMessageId: null,
            replyToProviderMessageId: null,
            origin: MessageOrigin::Flow,
            originRef: [],
            idempotencyKey: (MessageDirection::Inbound === $direction ? 'in:' : 'out:') . Str::ulid(),
            status: MessageDirection::Inbound === $direction ? DeliveryStatus::Received : DeliveryStatus::Sent,
            occurredAt: Carbon::now()->toImmutable(),
        );
    }
}
