<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Conversation;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Policies\ConversationPolicy;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Filament\Assistant\Resources\Conversations\ConversationResource;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\FeatureTestCase;

/**
 * Regression coverage for the inbox authorization hole: conversation transcripts
 * must be gated by {@see ConversationPolicy}
 * (ViewConversations / ReplyConversations), not merely by being an authenticated
 * staff user. Mirrors the style of {@see \Tests\Feature\Domains\Media\MediaResourceTest}.
 */
final class ConversationResourceAuthorizationTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_user_with_view_permission_can_load_conversation_index(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = $this->userWithAssistantAccess($assistant, [Permission::ViewConversations]);

        $this->actingAs($user);

        $this->get($this->indexUrl($assistant))->assertOk();
    }

    public function test_user_without_view_permission_cannot_load_conversation_index(): void
    {
        $assistant = Assistant::factory()->create();
        // Has tenant access (ManageAssistants + assignment) but not ViewConversations.
        $user = $this->userWithAssistantAccess($assistant, []);

        $this->actingAs($user);

        $this->get($this->indexUrl($assistant))->assertForbidden();
    }

    public function test_navigation_visibility_follows_permission(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo(Permission::ViewConversations->value);
        $this->actingAs($allowed);
        $this->assertTrue(ConversationResource::shouldRegisterNavigation());

        $denied = User::factory()->create();
        $this->actingAs($denied);
        $this->assertFalse(ConversationResource::shouldRegisterNavigation());
    }

    public function test_view_conversation_page_denied_without_permission(): void
    {
        $assistant    = Assistant::factory()->create();
        $conversation = $this->createConversation($assistant);
        $user         = $this->userWithAssistantAccess($assistant, []);

        $this->actingAs($user);

        $this->get($this->viewUrl($assistant, $conversation))->assertForbidden();
    }

    public function test_view_conversation_page_allowed_with_permission(): void
    {
        $assistant    = Assistant::factory()->create();
        $conversation = $this->createConversation($assistant);
        $user         = $this->userWithAssistantAccess($assistant, [Permission::ViewConversations]);

        $this->actingAs($user);

        $this->get($this->viewUrl($assistant, $conversation))->assertOk();
    }

    public function test_thread_of_other_assistant_returns_404_even_with_permission(): void
    {
        $ownAssistant   = Assistant::factory()->create();
        $otherAssistant = Assistant::factory()->create();
        $foreignThread  = $this->createConversation($otherAssistant);

        // User only has tenant access to $ownAssistant; the thread belongs to a
        // different assistant. getEloquentQuery() scoping must 404 it, not 403.
        $user = $this->userWithAssistantAccess($ownAssistant, [Permission::ViewConversations]);

        $this->actingAs($user);

        $this->get($this->viewUrl($ownAssistant, $foreignThread))->assertNotFound();
    }

    public function test_policy_reply_requires_reply_permission(): void
    {
        $assistant    = Assistant::factory()->create();
        $conversation = $this->createConversation($assistant);

        $withReply = User::factory()->create();
        $withReply->givePermissionTo(Permission::ReplyConversations->value);

        $withoutReply = User::factory()->create();
        $withoutReply->givePermissionTo(Permission::ViewConversations->value);

        $this->assertTrue(Gate::forUser($withReply)->allows('reply', $conversation));
        $this->assertFalse(Gate::forUser($withoutReply)->allows('reply', $conversation));
    }

    /**
     * ConversationPolicy::create()/update()/delete() must return false
     * unconditionally, per their docblock ("denied unconditionally rather than
     * gated behind a permission"). Exercised directly against the policy class
     * rather than through Gate::forUser(), because {@see \App\Providers\StaffServiceProvider}
     * registers an app-wide `Gate::before()` that short-circuits every ability
     * to `true` for any Admin-role user — see the bug note in the task report.
     */
    public function test_policy_denies_create_update_delete_unconditionally(): void
    {
        $assistant    = Assistant::factory()->create();
        $conversation = $this->createConversation($assistant);

        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);

        $policy = new ConversationPolicy();

        $this->assertFalse($policy->create($admin));
        $this->assertFalse($policy->update($admin, $conversation));
        $this->assertFalse($policy->delete($admin, $conversation));
    }

    /**
     * Gate-level check for a non-admin user, where the create/update/delete
     * denial actually takes effect end-to-end (no Admin bypass in play).
     */
    public function test_gate_denies_create_update_delete_for_non_admin_with_full_conversation_permissions(): void
    {
        $assistant    = Assistant::factory()->create();
        $conversation = $this->createConversation($assistant);

        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ViewConversations->value);
        $user->givePermissionTo(Permission::ReplyConversations->value);

        $this->assertFalse(Gate::forUser($user)->allows('create', Conversation::class));
        $this->assertFalse(Gate::forUser($user)->allows('update', $conversation));
        $this->assertFalse(Gate::forUser($user)->allows('delete', $conversation));
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

    private function createConversation(Assistant $assistant): Conversation
    {
        $contact = Contact::factory()->create(['tenant_id' => self::TENANT_ID]);
        // withoutEvents: ChannelObserver requires tenant context, which is only
        // resolved by TenancyMiddleware during an HTTP request, not while
        // building fixtures directly.
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
        ]);
    }

    private function indexUrl(Assistant $assistant): string
    {
        return route('filament.assistant.resources.conversations.index', ['tenant' => $assistant]);
    }

    private function viewUrl(Assistant $assistant, Conversation $conversation): string
    {
        return route('filament.assistant.resources.conversations.view', [
            'tenant' => $assistant,
            'record' => $conversation,
        ]);
    }
}
