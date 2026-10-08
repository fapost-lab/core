<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * The assistant dashboard on Inertia: Filament's figures and links, and the same 404 for an assistant the user may not open.
 */
final class ConsoleDashboardTest extends InertiaConsoleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_the_dashboard_shows_the_assistant_and_its_flow_activity(): void
    {
        $assistant = Assistant::factory()->create(['name' => 'Helper']);
        $this->flowSession($assistant, FlowSessionStatus::Active, withError: true);
        $this->flowSession($assistant, FlowSessionStatus::Completed);
        $this->flowSession(Assistant::factory()->create(), FlowSessionStatus::Active, withError: true);

        $this->actingAs($this->admin())
            ->get($this->panelUrl("/assistant/{$assistant->getKey()}/dashboard"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Dashboard')
                ->where('assistant.id', (string) $assistant->getKey())
                ->where('assistant.name', 'Helper')
                ->where('assistant.isActive', true)
                ->where('assistant.channelsCount', 0)
                ->where('operations.liveSessions', 1)
                ->where('operations.errors24h', 1)
                ->where('operations.sessionsUrl', "/assistant/{$assistant->getKey()}/flow-sessions")
                ->where('operations.logsUrl', "/assistant/{$assistant->getKey()}/flow-logs")
                ->where('navigation.mode', 'console')
                ->etc());
    }

    public function test_a_user_who_may_not_open_the_lists_gets_no_links_to_them(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $user->assistants()->attach($assistant);

        $this->actingAs($user)
            ->get($this->panelUrl("/assistant/{$assistant->getKey()}/dashboard"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('operations.sessionsUrl', null)
                ->where('operations.logsUrl', null)
                ->etc());
    }

    public function test_an_assistant_the_user_is_not_assigned_to_is_not_found(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);

        $this->actingAs($user)->get($this->panelUrl("/assistant/{$assistant->getKey()}/dashboard"))->assertNotFound();
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $assistant = Assistant::factory()->create();

        $this->get($this->panelUrl("/assistant/{$assistant->getKey()}/dashboard"))->assertRedirect(route('filament.admin.auth.login'));
    }

    private function flowSession(Assistant $assistant, FlowSessionStatus $status, bool $withError = false): FlowSession
    {
        $tenantId = (string) $assistant->tenant_id;
        $contact  = Contact::factory()->forTenant($tenantId)->create();
        $flow     = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $session = FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $flow->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-1',
            'state'              => [],
            'status'             => $status,
            'version'            => 0,
        ]);

        if ($withError) {
            FlowLog::query()->create([
                'session_id'   => $session->getKey(),
                'node_id'      => 'node-1',
                'node_type'    => 'send_message',
                'node_version' => 1,
                'status'       => 'failed',
                'error'        => ['message' => 'boom'],
                'created_at'   => now(),
            ]);
        }

        return $session;
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }
}
