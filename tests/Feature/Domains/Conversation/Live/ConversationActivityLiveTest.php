<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Conversation\Live;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\DeliveryStatus;
use App\Domains\Conversation\Enums\MessageContentType;
use App\Domains\Conversation\Enums\MessageDirection;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Conversation\Enums\MessageSenderType;
use App\Domains\Conversation\Jobs\PersistConversationMessageJob;
use App\Domains\Conversation\Live\ConversationActivityChanged;
use App\Domains\Conversation\Live\ConversationActivityChannel;
use App\Domains\Conversation\Live\ConversationActivityNotifier;
use App\Domains\Conversation\Live\ConversationActivityWatchers;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\ConversationMessage;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Infrastructure\Broadcasting\BroadcasterStatus;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * The server side of the inbox's live updates: a newly recorded message is announced to a watching inbox, only when a
 * broadcaster delivers, once per message; and only a user who may read the assistant's conversations may listen.
 */
final class ConversationActivityLiveTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());
        $this->assistant = Assistant::factory()->create();
    }

    public function test_a_recorded_message_is_announced_to_a_watcher_once(): void
    {
        config(['broadcasting.default' => 'pusher']);
        $this->app->make(ConversationActivityWatchers::class)->watch(self::TENANT_ID, (string) $this->assistant->getKey());
        Event::fake([ConversationActivityChanged::class]);

        $entry = $this->entry();
        $this->app->call([new PersistConversationMessageJob($entry), 'handle']);
        // The same message again (a retried job) is not recorded twice and announces nothing.
        $this->app->call([new PersistConversationMessageJob($entry), 'handle']);

        Event::assertDispatchedTimes(ConversationActivityChanged::class, 1);
        Event::assertDispatched(
            ConversationActivityChanged::class,
            fn (ConversationActivityChanged $event): bool => self::TENANT_ID === $event->tenantId
                && (string) $this->assistant->getKey() === $event->assistantId
                && [] === $event->broadcastWith(),
        );
    }

    public function test_nothing_is_announced_without_a_broadcaster_or_a_watcher(): void
    {
        Event::fake([ConversationActivityChanged::class]);

        $this->app->call([new PersistConversationMessageJob($this->entry()), 'handle']);

        config(['broadcasting.default' => 'pusher']);
        $this->app->call([new PersistConversationMessageJob($this->entry()), 'handle']);

        Event::assertNotDispatched(ConversationActivityChanged::class);
    }

    public function test_a_failing_announcement_is_reported_and_neither_fails_the_job_nor_loses_the_message(): void
    {
        config(['broadcasting.default' => 'pusher']);
        Exceptions::fake();
        $this->app->make(ConversationActivityWatchers::class)->watch(self::TENANT_ID, (string) $this->assistant->getKey());

        $events = Mockery::mock(Dispatcher::class);
        $events->shouldReceive('dispatch')->andThrow(new RuntimeException('The websocket server is down'));
        $this->app->instance(ConversationActivityNotifier::class, new ConversationActivityNotifier(
            $this->app->make(BroadcasterStatus::class),
            $this->app->make(ConversationActivityWatchers::class),
            $this->app->make(Cache::class),
            $events,
            $this->app->make(ExceptionHandler::class),
        ));

        $entry = $this->entry();
        $this->app->call([new PersistConversationMessageJob($entry), 'handle']);

        $this->assertTrue(ConversationMessage::query()->where('idempotency_key', $entry->idempotencyKey)->exists());
        $this->assertSame(1, Conversation::query()->where('contact_id', $entry->contactId)->value('unread_count'));
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_a_failing_cache_is_reported_too(): void
    {
        config(['broadcasting.default' => 'pusher']);
        Exceptions::fake();

        $cache = Mockery::mock(Cache::class);
        $cache->shouldReceive('has')->andThrow(new RuntimeException('Redis is gone'));
        $this->app->instance(ConversationActivityNotifier::class, new ConversationActivityNotifier(
            $this->app->make(BroadcasterStatus::class),
            new ConversationActivityWatchers($this->app->make(BroadcasterStatus::class), $cache),
            $cache,
            $this->app->make(Dispatcher::class),
            $this->app->make(ExceptionHandler::class),
        ));

        $entry = $this->entry();
        $this->app->call([new PersistConversationMessageJob($entry), 'handle']);

        $this->assertTrue(ConversationMessage::query()->where('idempotency_key', $entry->idempotencyKey)->exists());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_only_a_reader_of_the_assistants_conversations_may_listen(): void
    {
        $channel   = $this->app->make(ConversationActivityChannel::class);
        $assistant = (string) $this->assistant->getKey();

        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);
        $this->assertTrue($channel->join($admin, self::TENANT_ID, $assistant));

        $reader = User::factory()->create();
        $reader->givePermissionTo([Permission::ManageAssistants->value, Permission::ViewConversations->value]);
        $reader->assistants()->attach($this->assistant);
        $this->assertTrue($channel->join($reader, self::TENANT_ID, $assistant));

        $noRight = User::factory()->create();
        $noRight->givePermissionTo([Permission::ManageAssistants->value]);
        $noRight->assistants()->attach($this->assistant);
        $this->assertFalse($channel->join($noRight, self::TENANT_ID, $assistant));

        $this->assertFalse($channel->join($admin, '00000000-0000-0000-0000-0000000000ff', $assistant), 'another tenant');
        $this->assertFalse($channel->join($admin, self::TENANT_ID, 'not-a-uuid'));
        $this->assertFalse($channel->join($admin, self::TENANT_ID, (string) Str::uuid()));
    }

    private function entry(): MessageLogEntry
    {
        $contact = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $this->assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
        ]));

        return new MessageLogEntry(
            tenantId: self::TENANT_ID,
            assistantId: (string) $this->assistant->getKey(),
            contactId: (string) $contact->getKey(),
            channelId: (string) $channel->getKey(),
            platform: 'telegram',
            direction: MessageDirection::Inbound,
            senderType: MessageSenderType::Contact,
            contentType: MessageContentType::Text,
            text: 'hello',
            payload: [],
            media: [],
            providerMessageId: null,
            replyToProviderMessageId: null,
            origin: MessageOrigin::Flow,
            originRef: [],
            idempotencyKey: 'in:' . Str::ulid(),
            status: DeliveryStatus::Received,
            occurredAt: Carbon::now()->toImmutable(),
        );
    }
}
