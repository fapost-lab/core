<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Enums\ConversationStatus;
use App\Domains\Conversation\Live\ConversationActivityWatchers;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Services\ConversationInbox;
use App\Domains\Media\Contracts\MediaDispatcherInterface;
use App\Domains\Media\DTO\DispatchResult;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Messaging\Exceptions\RateLimitExceededException;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Media\Enums\MediaKind;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Fapost\Foundation\Quota\Contracts\UsageMeterInterface;
use Fapost\Foundation\Quota\Exceptions\VolumeLimitReachedException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Support\FakeUsageMeter;

/**
 * The conversation inbox on the Inertia console: which threads an assistant lists and counts as unread, a thread's
 * transcript and when it is marked read, the operator's controls, and a reply that reaches a real person exactly once,
 * through the outbound path and its volume gate, and only on the thread's own channel.
 */
final class ConversationsConsoleTest extends InertiaConsoleTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-0000000000ff';

    private Assistant $assistant;

    /** @var list<OutboundMessage> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());

        $this->assistant = Assistant::factory()->create();
    }

    public function test_the_list_shows_the_assistants_threads_and_the_menu_counts_the_same_unread(): void
    {
        $unread = $this->thread(['unread_count' => 2, 'message_count' => 5, 'last_message_preview' => 'Hi there', 'last_message_at' => Carbon::now()], meta: ['first_name' => 'Ann', 'last_name' => 'Lee']);
        $this->thread(['last_message_at' => Carbon::now()->subDay(), 'owner_type' => ConversationOwner::Staff]);

        $sibling = Assistant::factory()->create();
        $this->thread(['unread_count' => 4], assistant: $sibling);

        $foreign = Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]);
        $this->thread(['unread_count' => 7, 'tenant_id' => self::OTHER_TENANT_ID], assistant: $foreign);

        $this->actingAs($this->admin())
            ->get($this->listUrl())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Conversations/Index')
                ->where('table.meta.total', 2)
                ->where('table.state', ['search' => '', 'sort' => '-last_message_at', 'perPage' => 25, 'filters' => []])
                ->where('table.rows.0.id', (string) $unread->getKey())
                ->where('table.rows.0.contact', 'Ann Lee')
                ->where('table.rows.0.platform', 'telegram')
                ->where('table.rows.0.preview', 'Hi there')
                ->where('table.rows.0.unread', 2)
                ->where('table.rows.0.messages', 5)
                ->where('table.rows.0.status', 'open')
                ->where('table.rows.0.owner', 'bot')
                ->where('table.rows.0.viewUrl', "/assistant/{$this->assistant->getKey()}/conversations/{$unread->getKey()}")
                ->where('table.rows.1.owner', 'staff')
                ->where('statuses', ['open', 'closed', 'snoozed'])
                ->where('live', [
                    'channel' => 'tenant.' . self::TENANT_ID . ".assistant.{$this->assistant->getKey()}.conversations",
                    'event'   => 'conversation.activity',
                ])
                ->where('navigation.groups', fn ($groups): bool => '1' === $this->badgeOf($groups, 'filament.assistant.resources.conversations.index'))
                ->etc());
    }

    public function test_the_list_searches_the_contact_id_and_filters_by_status(): void
    {
        $wanted = $this->thread(externalId: 'alice-42');
        $this->thread(externalId: 'bob-7');
        $closed = $this->thread(['status' => ConversationStatus::Closed]);

        $this->actingAs($this->admin())
            ->get($this->listUrl('?search=ALICE'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.id', (string) $wanted->getKey())
                ->etc());

        $this->actingAs($this->admin())
            ->get($this->listUrl('?filter[status]=closed'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.id', (string) $closed->getKey())
                ->where('table.state.filters', ['status' => 'closed'])
                ->etc());
    }

    public function test_a_thread_shows_its_latest_messages_in_order_and_pages_back(): void
    {
        $thread = $this->thread(['message_count' => 60, 'unread_count' => 3], externalId: 'alice-42');
        $start  = Carbon::parse('2026-10-01 10:00:00');

        for ($i = 0; $i < 60; $i++) {
            $this->message($thread, ['text' => "m{$i}", 'created_at' => $start->copy()->addMinutes($i)]);
        }

        $this->actingAs($this->admin())
            ->get($this->viewUrl($thread))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Conversations/Show')
                ->where('conversation.id', (string) $thread->getKey())
                ->where('conversation.owner', 'bot')
                ->has('messages', 50)
                ->where('messages.0.text', 'm10')
                ->where('messages.49.text', 'm59')
                ->where('messages.0.author', 'alice-42')
                ->where('paging', ['limit' => 50, 'hasMore' => true, 'older' => 100])
                ->where('can', ['reply' => true, 'compose' => false, 'takeOver' => true, 'returnToBot' => false, 'setStatus' => true])
                ->etc());

        $this->actingAs($this->admin())
            ->get($this->viewUrl($thread, '?messages=100'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('messages', 60)
                ->where('messages.0.text', 'm0')
                ->where('paging.hasMore', false)
                ->etc());
    }

    public function test_opening_a_thread_marks_it_read_only_after_authorization_and_only_on_a_full_visit(): void
    {
        $thread  = $this->thread(['unread_count' => 3]);
        $sibling = $this->thread(['unread_count' => 2], assistant: Assistant::factory()->create());

        // No permission: forbidden, and the counter stays.
        $this->actingAs($this->userWith([]))->get($this->viewUrl($thread))->assertForbidden();
        $this->assertSame(3, $thread->fresh()?->unread_count);

        // Another assistant's thread: not found, and its counter stays.
        $this->actingAs($this->admin())->get($this->viewUrl($sibling))->assertNotFound();
        $this->assertSame(2, $sibling->fresh()?->unread_count);

        // A live reload of the open page does not mark what arrived meanwhile as read.
        $this->actingAs($this->admin())
            ->get($this->viewUrl($thread), ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Console/Conversations/Show', 'X-Inertia-Partial-Data' => 'messages'])
            ->assertOk();
        $this->assertSame(3, $thread->fresh()?->unread_count);

        $this->actingAs($this->userWith([Permission::ViewConversations]))->get($this->viewUrl($thread))->assertOk();
        $this->assertSame(0, $thread->fresh()?->unread_count);
    }

    public function test_another_tenants_thread_or_a_malformed_id_is_not_found(): void
    {
        $foreign = $this->thread(['tenant_id' => self::OTHER_TENANT_ID], assistant: Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]));

        $this->actingAs($this->admin())->get($this->viewUrl($foreign))->assertNotFound();
        $this->actingAs($this->admin())->get($this->listUrl('/not-a-uuid'))->assertNotFound();
        $this->actingAs($this->admin())->get($this->listUrl('/' . Str::ulid()->toRfc4122()))->assertNotFound();
    }

    public function test_the_transcript_links_only_this_tenants_media_and_never_a_storage_path(): void
    {
        $thread  = $this->thread();
        $own     = $this->mediaFile(self::TENANT_ID, 'own');
        $foreign = $this->mediaFile(self::OTHER_TENANT_ID, 'foreign');

        $this->message($thread, ['content_type' => 'photo', 'media' => json_encode([
            ['kind' => 'image', 'status' => 'ready', 'media_file_id' => (string) $own->getKey(), 'file_name' => 'own.jpg'],
            ['kind' => 'image', 'status' => 'ready', 'media_file_id' => (string) $foreign->getKey(), 'file_name' => 'foreign.jpg'],
            ['kind' => 'document', 'status' => 'pending', 'file_name' => 'later.pdf'],
        ])]);

        $response = $this->actingAs($this->admin())->get($this->viewUrl($thread))->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->where('messages.0.media.0.url', fn (?string $url): bool => null !== $url && str_contains($url, "/files/{$own->getKey()}/raw") && str_contains($url, 'signature='))
            ->where('messages.0.media.1.url', null)
            ->where('messages.0.media.2', ['kind' => 'document', 'fileName' => 'later.pdf', 'status' => 'pending', 'url' => null])
            ->etc());
        $this->assertStringNotContainsString('tenants/', (string) $response->getContent());
    }

    public function test_the_screens_need_the_conversations_permission(): void
    {
        $thread = $this->thread();

        $this->actingAs($this->userWith([]))->get($this->listUrl())->assertForbidden();

        $viewer = $this->userWith([Permission::ViewConversations]);
        $this->actingAs($viewer)->get($this->listUrl())->assertOk();
        $this->actingAs($viewer)
            ->get($this->viewUrl($thread))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can', ['reply' => false, 'compose' => false, 'takeOver' => false, 'returnToBot' => false, 'setStatus' => false])
                ->etc());

        foreach ([['post', 'reply'], ['post', 'takeover'], ['post', 'return-to-bot'], ['put', 'status']] as [$verb, $action]) {
            $this->actingAs($viewer)->{$verb}($this->writeUrl($thread, $action), ['request_id' => (string) Str::uuid(), 'text' => 'x', 'status' => 'closed'])->assertForbidden();
        }

        $this->assertSame([], $this->sent);
        $this->assertSame(ConversationStatus::Open, $thread->fresh()?->status);
        $this->assertNull($thread->fresh()?->owner_type);
    }

    public function test_take_over_hand_back_and_status_are_conditional_writes(): void
    {
        $thread   = $this->thread();
        $operator = $this->userWith([Permission::ViewConversations, Permission::ReplyConversations]);
        $other    = $this->userWith([Permission::ViewConversations, Permission::ReplyConversations]);

        $this->actingAs($operator)->post($this->writeUrl($thread, 'takeover'))->assertRedirect()
            ->assertInertiaFlash('success', __('conversation.notifications.taken_over'));
        $this->assertSame(ConversationOwner::Staff, $thread->fresh()?->owner_type);
        $this->assertSame((string) $operator->getKey(), $thread->fresh()?->owner_staff_user_id);

        // A second operator (or a stale tab) does not take the thread away silently.
        $this->actingAs($other)->post($this->writeUrl($thread, 'takeover'))
            ->assertInertiaFlash('error', __('conversation.notifications.already_taken_over'));
        $this->assertSame((string) $operator->getKey(), $thread->fresh()?->owner_staff_user_id);

        // The status is set, not toggled: closing twice leaves it closed.
        $this->actingAs($operator)->put($this->writeUrl($thread, 'status'), ['status' => 'closed'])->assertRedirect();
        $this->actingAs($operator)->put($this->writeUrl($thread, 'status'), ['status' => 'closed'])->assertRedirect();
        $this->assertSame(ConversationStatus::Closed, $thread->fresh()?->status);
        $this->actingAs($operator)->put($this->writeUrl($thread, 'status'), ['status' => 'snoozed'])->assertSessionHasErrors('status');

        $this->actingAs($operator)->post($this->writeUrl($thread, 'return-to-bot'))
            ->assertInertiaFlash('success', __('conversation.notifications.returned_to_bot'));
        $this->assertSame(ConversationOwner::Bot, $thread->fresh()?->owner_type);
        $this->assertNull($thread->fresh()?->owner_staff_user_id);
    }

    public function test_a_reply_is_sent_once_on_the_threads_own_channel_however_often_it_is_submitted(): void
    {
        $this->fakeSender();
        $thread    = $this->heldThread();
        $requestId = (string) Str::uuid();

        $this->actingAs($this->admin())
            ->post($this->writeUrl($thread, 'reply'), ['request_id' => $requestId, 'text' => '  Hello there  '])
            ->assertRedirect()
            ->assertInertiaFlash('success', __('conversation.notifications.reply_sent'));

        // A double click, a retried request, a replayed form or a second tab holding the same draft.
        $this->actingAs($this->admin())
            ->post($this->writeUrl($thread, 'reply'), ['request_id' => $requestId, 'text' => 'Hello there'])
            ->assertInertiaFlash('success', __('conversation.notifications.reply_duplicate'));

        $this->assertCount(1, $this->sent);
        $message = $this->sent[0];
        $this->assertSame('Hello there', $message->payload->text);
        $this->assertSame('chat-' . $thread->contact_id, $message->chatId);
        $this->assertSame((string) $thread->channel_id, $message->channelId);
        $this->assertSame("staff_reply:{$thread->getKey()}:{$requestId}", $message->idempotencyKey);
        $this->assertSame((string) $this->assistant->getKey(), $message->metadata['assistant_id']);

        // Another draft is another message.
        $this->actingAs($this->admin())->post($this->writeUrl($thread, 'reply'), ['request_id' => (string) Str::uuid(), 'text' => 'And one more']);
        $this->assertCount(2, $this->sent);
    }

    public function test_a_submission_in_flight_or_already_sent_elsewhere_sends_nothing(): void
    {
        $this->fakeSender();
        $thread  = $this->heldThread();
        $pending = (string) Str::uuid();
        $sent    = (string) Str::uuid();

        Cache::add($this->reservation($thread, $pending), 'pending', 60);
        Cache::add($this->reservation($thread, $sent), 'sent', 60);

        // In flight: not confirmed, flashed as an error so the screen keeps the draft and its id.
        $this->actingAs($this->admin())
            ->post($this->writeUrl($thread, 'reply'), ['request_id' => $pending, 'text' => 'Hello'])
            ->assertInertiaFlash('error', __('conversation.notifications.reply_unconfirmed'));

        $this->actingAs($this->admin())
            ->post($this->writeUrl($thread, 'reply'), ['request_id' => $sent, 'text' => 'Hello'])
            ->assertInertiaFlash('success', __('conversation.notifications.reply_duplicate'));

        $this->assertSame([], $this->sent);
    }

    public function test_a_duplicate_answer_from_the_outbound_path_is_not_confirmed_and_keeps_the_draft(): void
    {
        $calls = 0;
        $this->fakeSender(function () use (&$calls): DeliveryResult {
            $calls++;

            // MessageSender answers so while its key is still marked in flight by an attempt that died mid-send.
            return new DeliveryResult(sent: false, duplicate: true);
        });
        $thread    = $this->heldThread();
        $requestId = (string) Str::uuid();

        $this->actingAs($this->admin())
            ->post($this->writeUrl($thread, 'reply'), ['request_id' => $requestId, 'text' => 'Hello'])
            ->assertInertiaFlash('error', __('conversation.notifications.reply_unconfirmed'));

        $this->assertSame(1, $calls);
        $this->assertNotSame('sent', Cache::get($this->reservation($thread, $requestId)), 'nothing claims the reply was sent');
    }

    public function test_a_definitely_failed_reply_is_released_for_a_retry_that_goes_out_once(): void
    {
        $attempts = 0;
        $this->fakeSender(function () use (&$attempts): DeliveryResult {
            $attempts++;

            return match ($attempts) {
                1       => new DeliveryResult(sent: false, error: 'provider said no'),
                2       => throw new RateLimitExceededException('Channel rate limit exceeded.'),
                default => new DeliveryResult(sent: true, providerMessageId: 'p-1'),
            };
        });
        $thread    = $this->heldThread();
        $requestId = (string) Str::uuid();
        $submit    = fn () => $this->actingAs($this->admin())->post($this->writeUrl($thread, 'reply'), ['request_id' => $requestId, 'text' => 'Hello']);

        $submit()->assertInertiaFlash('error', __('conversation.notifications.reply_failed'));
        $submit()->assertInertiaFlash('error', __('conversation.notifications.reply_failed'));
        $submit()->assertInertiaFlash('success', __('conversation.notifications.reply_sent'));
        $submit()->assertInertiaFlash('success', __('conversation.notifications.reply_duplicate'));

        $this->assertSame(3, $attempts);
        $this->assertSame(["staff_reply:{$thread->getKey()}:{$requestId}"], array_values(array_unique(array_map(static fn (OutboundMessage $message): string => $message->idempotencyKey, $this->sent))));
    }

    public function test_an_ambiguous_failure_keeps_the_submission_pending_until_it_expires(): void
    {
        Exceptions::fake();
        $attempts = 0;
        $this->fakeSender(function () use (&$attempts): DeliveryResult {
            $attempts++;

            if (1 === $attempts) {
                throw new RuntimeException('read timeout after the request was written');
            }

            return new DeliveryResult(sent: true, providerMessageId: 'p-1');
        });
        $thread    = $this->heldThread();
        $requestId = (string) Str::uuid();
        $submit    = fn () => $this->actingAs($this->admin())->post($this->writeUrl($thread, 'reply'), ['request_id' => $requestId, 'text' => 'Hello']);

        $submit()->assertInertiaFlash('error', __('conversation.notifications.reply_unconfirmed'));
        Exceptions::assertReported(RuntimeException::class);

        // The provider may have the message: an immediate retry sends nothing.
        $submit()->assertInertiaFlash('error', __('conversation.notifications.reply_unconfirmed'));
        $this->assertSame(1, $attempts);

        // Once the pending mark has lapsed, the operator who checked the transcript can send it.
        $this->travel(ConversationInbox::PENDING_SECONDS + 1)->seconds();
        $submit()->assertInertiaFlash('success', __('conversation.notifications.reply_sent'));
        $this->assertSame(2, $attempts);
    }

    public function test_an_attachment_is_removed_when_the_send_definitely_fails_and_kept_when_it_is_not_confirmed(): void
    {
        $outcome = 'refused';
        $this->fakeSender(function () use (&$outcome): DeliveryResult {
            if ('refused' === $outcome) {
                return new DeliveryResult(sent: false, error: 'bad file');
            }

            throw new RuntimeException('connection reset');
        });
        Exceptions::fake();
        $this->app->instance(UsageMeterInterface::class, FakeUsageMeter::allowing());
        $this->mock(MediaDispatcherInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ensureUploadedToChannel')->andReturn(new DispatchResult(providerFileId: 'file-1', alreadyDelivered: false));
        });
        $thread = $this->heldThread();

        $this->actingAs($this->admin())
            ->post($this->writeUrl($thread, 'reply'), ['request_id' => (string) Str::uuid(), 'attachment' => UploadedFile::fake()->image('refused.jpg')])
            ->assertInertiaFlash('error', __('conversation.notifications.reply_failed'));
        $this->assertSame(0, MediaFile::query()->where('name', 'refused.jpg')->count());

        $outcome = 'ambiguous';
        $this->actingAs($this->admin())
            ->post($this->writeUrl($thread, 'reply'), ['request_id' => (string) Str::uuid(), 'attachment' => UploadedFile::fake()->image('maybe.jpg')])
            ->assertInertiaFlash('error', __('conversation.notifications.reply_unconfirmed'));
        $this->assertSame(1, MediaFile::query()->where('name', 'maybe.jpg')->count());
    }

    public function test_a_reply_refused_by_the_volume_gate_says_so_and_can_be_sent_later(): void
    {
        $refuse = true;
        $this->fakeSender(function () use (&$refuse): DeliveryResult {
            if ($refuse) {
                throw new VolumeLimitReachedException('outbound_messages', 10, 10);
            }

            return new DeliveryResult(sent: true);
        });
        $thread    = $this->heldThread();
        $requestId = (string) Str::uuid();

        $refused = $this->actingAs($this->admin())->post($this->writeUrl($thread, 'reply'), ['request_id' => $requestId, 'text' => 'Hello']);
        $this->assertStringStartsWith(__('conversation.notifications.reply_limit_reached'), $this->flash($refused, 'error'));

        $refuse = false;
        $this->actingAs($this->admin())
            ->post($this->writeUrl($thread, 'reply'), ['request_id' => $requestId, 'text' => 'Hello'])
            ->assertInertiaFlash('success', __('conversation.notifications.reply_sent'));
    }

    public function test_an_attachment_refused_by_the_volume_gate_is_neither_uploaded_to_the_channel_nor_sent(): void
    {
        $this->fakeSender();
        $this->app->instance(UsageMeterInterface::class, FakeUsageMeter::denying(10, 10));
        $this->mock(MediaDispatcherInterface::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('ensureUploadedToChannel');
        });
        $thread = $this->heldThread();

        $refused = $this->actingAs($this->admin())
            ->post($this->writeUrl($thread, 'reply'), ['request_id' => (string) Str::uuid(), 'attachment' => UploadedFile::fake()->image('photo.jpg')]);
        $this->assertStringStartsWith(__('conversation.notifications.reply_limit_reached'), $this->flash($refused, 'error'));

        $this->assertSame([], $this->sent);
        $this->assertSame(0, MediaFile::query()->count(), 'the refused attachment is not left in the library');
    }

    public function test_an_attachment_is_kept_in_the_inbox_folder_and_sent_with_its_caption(): void
    {
        $this->fakeSender();
        $this->app->instance(UsageMeterInterface::class, FakeUsageMeter::allowing());
        $this->mock(MediaDispatcherInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ensureUploadedToChannel')->once()->andReturn(new DispatchResult(providerFileId: 'file-1', alreadyDelivered: false));
        });
        $thread = $this->heldThread();

        $this->actingAs($this->admin())
            ->post($this->writeUrl($thread, 'reply'), ['request_id' => (string) Str::uuid(), 'text' => 'See this', 'attachment' => UploadedFile::fake()->image('photo.jpg')])
            ->assertInertiaFlash('success', __('conversation.notifications.reply_sent'));

        $this->assertCount(1, $this->sent);
        $this->assertSame('photo', $this->sent[0]->payload->type);
        $this->assertSame('See this', $this->sent[0]->payload->text);
        $file = MediaFile::query()->where('tenant_id', self::TENANT_ID)->where('name', 'photo.jpg')->sole();
        $this->assertSame('Inbox', MediaFolder::query()->find($file->folder_id)?->name);
    }

    public function test_an_attachment_outside_the_media_limits_is_refused_before_anything_is_sent(): void
    {
        $this->fakeSender();
        $thread = $this->heldThread();

        $this->actingAs($this->admin())
            ->post($this->writeUrl($thread, 'reply'), ['request_id' => (string) Str::uuid(), 'attachment' => UploadedFile::fake()->create('big.pdf', 20481, 'application/pdf')])
            ->assertSessionHasErrors('attachment');
        $this->actingAs($this->admin())
            ->post($this->writeUrl($thread, 'reply'), ['request_id' => (string) Str::uuid(), 'attachment' => UploadedFile::fake()->create('tool.exe', 10, 'application/x-msdownload')])
            ->assertSessionHasErrors('attachment');
        $this->actingAs($this->admin())
            ->post($this->writeUrl($thread, 'reply'), ['text' => 'no submission id'])
            ->assertSessionHasErrors('request_id');
        $this->actingAs($this->admin())
            ->post($this->writeUrl($thread, 'reply'), ['request_id' => (string) Str::uuid(), 'text' => ''])
            ->assertSessionHasErrors('text');

        $this->assertSame([], $this->sent);
        $this->assertSame(0, MediaFile::query()->count());
    }

    public function test_a_reply_needs_the_thread_taken_over_and_never_reaches_another_assistants_or_tenants_contact(): void
    {
        $this->fakeSender();
        $botThread = $this->thread();
        $sibling   = $this->heldThread(Assistant::factory()->create());
        $foreignAs = Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]);
        $foreign   = $this->thread(['tenant_id' => self::OTHER_TENANT_ID, 'owner_type' => ConversationOwner::Staff], assistant: $foreignAs);

        $this->actingAs($this->admin())
            ->post($this->writeUrl($botThread, 'reply'), ['request_id' => (string) Str::uuid(), 'text' => 'Hi'])
            ->assertInertiaFlash('error', __('conversation.notifications.reply_requires_takeover'));

        foreach ([$sibling, $foreign] as $thread) {
            $this->actingAs($this->admin())
                ->post($this->writeUrl($thread, 'reply'), ['request_id' => (string) Str::uuid(), 'text' => 'Hi'])
                ->assertNotFound();
        }

        $this->assertSame([], $this->sent);
    }

    public function test_a_thread_without_an_active_channel_says_it_cannot_be_reached(): void
    {
        $this->fakeSender();
        $thread = $this->heldThread();
        Channel::query()->whereKey($thread->channel_id)->update(['is_active' => false]);

        $this->actingAs($this->admin())
            ->post($this->writeUrl($thread, 'reply'), ['request_id' => (string) Str::uuid(), 'text' => 'Hi'])
            ->assertInertiaFlash('error', __('conversation.notifications.reply_undeliverable'));

        $this->assertSame([], $this->sent);
    }

    public function test_an_open_screen_marks_the_assistant_watched_only_when_a_broadcaster_delivers(): void
    {
        $watchers = $this->app->make(ConversationActivityWatchers::class);

        $this->actingAs($this->admin())->get($this->listUrl())->assertOk();
        $this->assertFalse($watchers->isWatched(self::TENANT_ID, (string) $this->assistant->getKey()));

        config(['broadcasting.default' => 'pusher']);
        $this->actingAs($this->admin())->get($this->viewUrl($this->thread()))->assertOk();
        $this->assertTrue($watchers->isWatched(self::TENANT_ID, (string) $this->assistant->getKey()));
    }

    /**
     * @param  (callable(OutboundMessage): DeliveryResult)|null  $respond
     */
    private function fakeSender(?callable $respond = null): void
    {
        $this->mock(OutboundMessageSenderInterface::class, function (MockInterface $mock) use ($respond): void {
            $mock->shouldReceive('send')->andReturnUsing(function (OutboundMessage $message) use ($respond): DeliveryResult {
                $result = null !== $respond ? $respond($message) : new DeliveryResult(sent: true, providerMessageId: 'p-' . count($this->sent));

                if ($result->sent) {
                    $this->sent[] = $message;
                }

                return $result;
            });
        });
    }

    /**
     * A thread an operator holds, on an active Telegram channel the contact is linked to.
     */
    private function heldThread(?Assistant $assistant = null): Conversation
    {
        return $this->thread(['owner_type' => ConversationOwner::Staff, 'owner_staff_user_id' => (string) User::factory()->create()->getKey()], assistant: $assistant);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $meta
     */
    private function thread(array $attributes = [], ?Assistant $assistant = null, ?string $externalId = null, array $meta = []): Conversation
    {
        $assistant ??= $this->assistant;
        $tenantId = (string) ($attributes['tenant_id'] ?? self::TENANT_ID);
        $contact  = Contact::factory()->forTenant($tenantId)->create(['meta' => $meta]);
        $contact->forceFill(['external_id' => $externalId ?? 'chat-' . $contact->getKey()])->save();
        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => $tenantId,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));

        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => Carbon::now(),
        ]);

        return Conversation::query()->create([
            'tenant_id'    => $tenantId,
            'assistant_id' => $assistant->getKey(),
            'contact_id'   => $contact->getKey(),
            'channel_id'   => $channel->getKey(),
            'platform'     => 'telegram',
            'status'       => ConversationStatus::Open,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function message(Conversation $thread, array $attributes = []): void
    {
        DB::table('conversation_messages')->insert([
            'id'              => Str::ulid()->toRfc4122(),
            'tenant_id'       => $thread->tenant_id,
            'conversation_id' => $thread->getKey(),
            'contact_id'      => $thread->contact_id,
            'assistant_id'    => $thread->assistant_id,
            'channel_id'      => $thread->channel_id,
            'direction'       => 'inbound',
            'sender_type'     => 'contact',
            'content_type'    => 'text',
            'text'            => 'hello',
            'status'          => 'received',
            'origin'          => 'flow',
            'created_at'      => Carbon::now(),
            ...$attributes,
        ]);
    }

    private function mediaFile(string $tenantId, string $hashSeed): MediaFile
    {
        $blob = MediaBlob::query()->create([
            'tenant_id'    => $tenantId,
            'content_hash' => hash('sha256', $hashSeed),
            'storage_path' => "tenants/{$tenantId}/media/{$hashSeed}.jpg",
            'storage_disk' => 'local',
            'size'         => 10,
            'mime_type'    => 'image/jpeg',
        ]);

        return MediaFile::query()->create([
            'tenant_id' => $tenantId,
            'blob_id'   => $blob->id,
            'name'      => "{$hashSeed}.jpg",
            'kind'      => MediaKind::Image,
            'metadata'  => [],
            'source'    => MediaSource::Upload,
        ]);
    }

    /**
     * @param  list<array{label: string|null, items: list<array<string, mixed>>}>  $groups
     */
    private function badgeOf(mixed $groups, string $key): ?string
    {
        foreach (collect($groups)->all() as $group) {
            foreach ($group['items'] as $item) {
                if ($item['key'] === $key) {
                    return $item['badge'];
                }
            }
        }

        return null;
    }

    private function reservation(Conversation $thread, string $requestId): string
    {
        return "conversation-reply:{$thread->tenant_id}:{$thread->getKey()}:{$requestId}";
    }

    private function flash(TestResponse $response, string $kind): string
    {
        $flash = $response->baseRequest->session()->get(SessionKey::FLASH_DATA, []);

        return is_array($flash) && is_string($flash[$kind] ?? null) ? $flash[$kind] : '';
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        // Opening an assistant's console needs ManageAssistants; the conversation permissions are what the screen asks on top.
        $user->givePermissionTo([Permission::ManageAssistants->value, ...array_map(static fn (Permission $permission): string => $permission->value, $permissions)]);
        $user->assistants()->attach($this->assistant);

        return $user;
    }

    private function listUrl(string $suffix = ''): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/conversations{$suffix}");
    }

    private function viewUrl(Conversation $thread, string $query = ''): string
    {
        return $this->listUrl('/' . $thread->getKey() . $query);
    }

    private function writeUrl(Conversation $thread, string $action): string
    {
        return $this->listUrl('/' . $thread->getKey() . '/' . $action);
    }
}
