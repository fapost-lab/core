<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Conversation;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Contracts\ConversationReplyServiceInterface;
use App\Domains\Conversation\Enums\ConversationOwner;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Filament\Assistant\Resources\Conversations\Pages\ViewConversation;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Quota\Exceptions\VolumeLimitReachedException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Livewire\Livewire;
use Tests\Feature\FeatureTestCase;

/**
 * Covers the operator inbox surface of {@see ViewConversation}:
 * the reply action is gated behind {@see Permission::ReplyConversations}
 * (mirroring {@see ConversationResourceAuthorizationTest}'s style for the
 * view/reply split), and opening a thread clears its unread counter as a
 * side effect of {@code mount()}.
 */
final class ConversationInboxViewTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    /**
     * Permission alone does not open the composer: while the assistant owns the
     * thread an operator reply would cut across a running flow, so the input is
     * closed and the page offers the takeover instead.
     */
    public function test_composer_is_locked_while_the_assistant_owns_the_thread(): void
    {
        $assistant    = Assistant::factory()->create();
        $conversation = $this->createConversation($assistant);
        $user         = $this->userWithAssistantAccess($assistant, [Permission::ViewConversations, Permission::ReplyConversations]);

        $this->actingAs($user);

        $this->get($this->viewUrl($assistant, $conversation))
            ->assertOk()
            ->assertSee(__('conversation.reply.locked'))
            ->assertSee(__('conversation.actions.take_over'))
            ->assertDontSee(__('conversation.actions.reply'));
    }

    public function test_composer_opens_once_staff_owns_the_thread(): void
    {
        $assistant    = Assistant::factory()->create();
        $conversation = $this->createConversation($assistant, [
            'owner_type'          => ConversationOwner::Staff,
            'owner_staff_user_id' => (string) User::factory()->create()->getKey(),
        ]);
        $user = $this->userWithAssistantAccess($assistant, [Permission::ViewConversations, Permission::ReplyConversations]);

        $this->actingAs($user);

        $this->get($this->viewUrl($assistant, $conversation))
            ->assertOk()
            ->assertSee(__('conversation.actions.reply'))
            ->assertSee(__('conversation.reply.attach'))
            ->assertDontSee(__('conversation.reply.locked'));
    }

    public function test_reply_action_is_hidden_without_reply_permission(): void
    {
        $assistant    = Assistant::factory()->create();
        $conversation = $this->createConversation($assistant);
        $user         = $this->userWithAssistantAccess($assistant, [Permission::ViewConversations]);

        $this->actingAs($user);

        $this->get($this->viewUrl($assistant, $conversation))
            ->assertOk()
            ->assertDontSee(__('conversation.actions.reply'));
    }

    public function test_take_over_action_visible_when_bot_owns_thread(): void
    {
        $assistant    = Assistant::factory()->create();
        $conversation = $this->createConversation($assistant);
        $user         = $this->userWithAssistantAccess($assistant, [Permission::ViewConversations, Permission::ReplyConversations]);

        $this->actingAs($user);

        $this->get($this->viewUrl($assistant, $conversation))
            ->assertOk()
            ->assertSee(__('conversation.actions.take_over'))
            ->assertDontSee(__('conversation.actions.return_to_bot'));
    }

    public function test_return_to_bot_action_visible_when_staff_owns_thread(): void
    {
        $assistant    = Assistant::factory()->create();
        $conversation = $this->createConversation($assistant, [
            'owner_type'          => ConversationOwner::Staff,
            'owner_staff_user_id' => (string) User::factory()->create()->getKey(),
        ]);
        $user = $this->userWithAssistantAccess($assistant, [Permission::ViewConversations, Permission::ReplyConversations]);

        $this->actingAs($user);

        $this->get($this->viewUrl($assistant, $conversation))
            ->assertOk()
            ->assertSee(__('conversation.actions.return_to_bot'))
            ->assertDontSee(__('conversation.actions.take_over'));
    }

    public function test_opening_conversation_clears_unread_count(): void
    {
        $assistant    = Assistant::factory()->create();
        $conversation = $this->createConversation($assistant, ['unread_count' => 5]);
        $user         = $this->userWithAssistantAccess($assistant, [Permission::ViewConversations]);

        $this->actingAs($user);

        $this->get($this->viewUrl($assistant, $conversation))->assertOk();

        $this->assertSame(0, $conversation->fresh()->unread_count);
    }

    public function test_opening_conversation_without_permission_does_not_clear_unread_count(): void
    {
        $assistant    = Assistant::factory()->create();
        $conversation = $this->createConversation($assistant, ['unread_count' => 5]);
        $user         = $this->userWithAssistantAccess($assistant, []);

        $this->actingAs($user);

        $this->get($this->viewUrl($assistant, $conversation))->assertForbidden();

        $this->assertSame(5, $conversation->fresh()->unread_count);
    }

    public function test_reply_refused_by_the_volume_limit_tells_the_operator_instead_of_a_generic_failure(): void
    {
        $assistant    = Assistant::factory()->create();
        $conversation = $this->createConversation($assistant, [
            'owner_type'          => ConversationOwner::Staff,
            'owner_staff_user_id' => (string) User::factory()->create()->getKey(),
        ]);
        $user = $this->userWithAssistantAccess($assistant, [Permission::ViewConversations, Permission::ReplyConversations]);

        $this->app->instance(ConversationReplyServiceInterface::class, new class () implements ConversationReplyServiceInterface {
            public function send(Conversation $conversation, string $text, string $staffUserId, ?string $mediaFileId = null): DeliveryResult
            {
                throw new VolumeLimitReachedException('outbound_messages', 10, 10);
            }
        });

        $this->actingAs($user);
        Filament::setCurrentPanel('assistant');
        Filament::setTenant($assistant);

        Livewire::actingAs($user)
            ->test(ViewConversation::class, ['record' => $conversation->getKey()])
            ->set('replyText', 'Hello')
            ->call('sendComposerReply')
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title(__('conversation.notifications.reply_limit_reached'))
                    ->body('Limit "outbound_messages" reached: 10 of 10 this period.'),
            );
    }

    /**
     * @param  list<Permission>  $extraPermissions
     */
    private function userWithAssistantAccess(Assistant $assistant, array $extraPermissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        foreach ($extraPermissions as $permission) {
            $user->givePermissionTo($permission->value);
        }
        $user->assistants()->attach($assistant);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createConversation(Assistant $assistant, array $overrides = []): Conversation
    {
        $contact = Contact::factory()->create(['tenant_id' => self::TENANT_ID]);
        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
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

    private function viewUrl(Assistant $assistant, Conversation $conversation): string
    {
        return route('filament.assistant.resources.conversations.view', [
            'tenant' => $assistant,
            'record' => $conversation,
        ]);
    }
}
