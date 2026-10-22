<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Enums\ConversationStatus;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Middleware\ResolveCurrentAssistant;
use App\Http\Shell\ApplicationRegistry;
use App\Http\Shell\AssistantSwitcher;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureTestCase;

/**
 * An operator (a role with only conversation permissions) works in the threads of the assistants assigned to them: the
 * console opens for them through the `operate` ability, and every other screen still asks for its own permission.
 */
final class OperatorHandoffTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Assistant $assigned;

    private Assistant $other;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());

        $this->assigned = Assistant::factory()->create(['name' => 'Alpha']);
        $this->other    = Assistant::factory()->create(['name' => 'Beta']);

        $role = Role::query()->create(['name' => 'operator', 'display_name' => 'Operator', 'guard_name' => 'web', 'priority' => 10, 'is_system' => false]);
        $role->givePermissionTo([Permission::ViewConversations->value, Permission::ReplyConversations->value]);

        $this->operator = User::factory()->create();
        $this->operator->assignRole($role);
        $this->operator->assistants()->attach($this->assigned);
    }

    public function test_an_operator_opens_the_inbox_and_a_thread_of_the_assigned_assistant(): void
    {
        $thread = $this->thread($this->assigned);

        $this->actingAs($this->operator)->get($this->url($this->assigned, '/conversations'))->assertOk();
        $this->actingAs($this->operator)->get($this->url($this->assigned, "/conversations/{$thread->getKey()}"))->assertOk();
    }

    public function test_an_unassigned_assistant_is_not_found_for_the_operator(): void
    {
        $thread = $this->thread($this->other);

        $this->actingAs($this->operator)->get($this->url($this->other, '/conversations'))->assertNotFound();
        $this->actingAs($this->operator)->get($this->url($this->other, "/conversations/{$thread->getKey()}"))->assertNotFound();
    }

    public function test_the_operator_cannot_open_management_screens_of_the_assigned_assistant(): void
    {
        foreach (['/dashboard', '/settings', '/flows'] as $path) {
            $this->assertContains(
                $this->actingAs($this->operator)->get($this->url($this->assigned, $path))->getStatusCode(),
                [403, 404],
                $path,
            );
        }
    }

    public function test_the_switcher_lists_only_the_assigned_assistant_and_leads_to_the_inbox(): void
    {
        $switcher = app(AssistantSwitcher::class)->for($this->operator, $this->assigned);

        $this->assertSame([$this->inboxPath($this->assigned)], array_column($switcher['items'], 'href'));
        $this->assertSame([(string) $this->assigned->getKey()], array_column($switcher['items'], 'id'));
    }

    public function test_the_thread_policy_requires_assignment_to_the_threads_assistant(): void
    {
        $mine   = $this->thread($this->assigned);
        $theirs = $this->thread($this->other);

        $this->assertTrue($this->operator->can('view', $mine));
        $this->assertTrue($this->operator->can('reply', $mine));
        $this->assertFalse($this->operator->can('view', $theirs));
        $this->assertFalse($this->operator->can('reply', $theirs));
        $this->assertTrue($this->operator->can('viewAny', Conversation::class));
    }

    public function test_an_assigned_user_lands_on_the_first_screen_their_permissions_open(): void
    {
        $viewer = $this->assignedWith('session-viewer', [Permission::ViewFlowSessions]);
        $path   = route('console.flow-sessions.index', ['assistant' => (string) $this->assigned->getKey()], false);

        $applications = app(ApplicationRegistry::class)->for($viewer);

        $this->assertSame(ApplicationRegistry::CONSOLE, $applications[0]->key);
        $this->assertSame($path, $applications[0]->href);
        $this->assertSame([$path], array_column(app(AssistantSwitcher::class)->for($viewer, $this->assigned)['items'], 'href'));
        $this->actingAs($viewer)->get($this->panelUrl("/assistant/{$this->assigned->getKey()}"))
            ->assertRedirect(route('console.flow-sessions.index', ['assistant' => (string) $this->assigned->getKey()]));
    }

    public function test_a_user_assigned_with_no_openable_screen_gets_no_console_application_or_switcher_item(): void
    {
        $nobody = $this->assignedWith('reporter', [Permission::ViewAnalytics]);

        $keys = array_map(static fn ($application): string => $application->key, app(ApplicationRegistry::class)->for($nobody));

        $this->assertNotContains(ApplicationRegistry::CONSOLE, $keys);
        $this->assertSame([], app(AssistantSwitcher::class)->for($nobody, $this->assigned)['items']);
    }

    public function test_the_console_application_opens_on_the_inbox_for_the_operator(): void
    {
        $applications = app(ApplicationRegistry::class)->for($this->operator);

        $this->assertSame(ApplicationRegistry::CONSOLE, $applications[0]->key);
        $this->assertSame($this->inboxPath($this->assigned), $applications[0]->href);
    }

    public function test_the_assistant_addresses_send_the_operator_to_the_inbox(): void
    {
        // The expected URL is built after each request, on the host that request used, as the sibling console tests do.
        $inbox = fn (): string => route('console.conversations.index', ['assistant' => (string) $this->assigned->getKey()]);

        $this->actingAs($this->operator)->get($this->panelUrl('/assistant'))->assertRedirect($inbox());
        $this->actingAs($this->operator)->get($this->panelUrl("/assistant/{$this->assigned->getKey()}"))->assertRedirect($inbox());
        $this->actingAs($this->operator)->get($this->panelUrl("/assistant/{$this->other->getKey()}"))->assertNotFound();
    }

    public function test_the_default_ability_of_the_middleware_still_requires_view(): void
    {
        Route::middleware(['web', 'auth', 'tenant', ResolveCurrentAssistant::class])->get('/_test/view/{assistant}', fn (): string => 'ok');
        Route::middleware(['web', 'auth', 'tenant', ResolveCurrentAssistant::class . ':assistant,operate'])->get('/_test/operate/{assistant}', fn (): string => 'ok');

        $this->actingAs($this->operator)->get("/_test/view/{$this->assigned->getKey()}")->assertNotFound();
        $this->actingAs($this->operator)->get("/_test/operate/{$this->assigned->getKey()}")->assertOk();
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private function assignedWith(string $roleName, array $permissions): User
    {
        $role = Role::query()->create(['name' => $roleName, 'display_name' => $roleName, 'guard_name' => 'web', 'priority' => 5, 'is_system' => false]);
        $role->givePermissionTo(array_map(static fn (Permission $permission): string => $permission->value, $permissions));
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->assistants()->attach($this->assigned);

        return $user;
    }

    private function inboxPath(Assistant $assistant): string
    {
        return route('console.conversations.index', ['assistant' => $assistant->getKey()], false);
    }

    private function url(Assistant $assistant, string $path): string
    {
        return $this->panelUrl("/assistant/{$assistant->getKey()}{$path}");
    }

    private function thread(Assistant $assistant): Conversation
    {
        $contact = Contact::factory()->forTenant(self::TENANT_ID)->create();
        $contact->forceFill(['external_id' => 'chat-' . $contact->getKey()])->save();
        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
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
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $assistant->getKey(),
            'contact_id'   => $contact->getKey(),
            'channel_id'   => $channel->getKey(),
            'platform'     => 'telegram',
            'status'       => ConversationStatus::Open,
        ]);
    }
}
