<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\LimitRefusal;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowLog;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use DateTimeInterface;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\Support\FakeTenantLimits;

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

    public function test_the_dashboard_reports_who_the_contact_limit_turned_away_in_the_last_30_days(): void
    {
        $this->travelTo(now()->startOfSecond());
        $assistant = Assistant::factory()->create();
        $channel   = $this->channel($assistant);
        $other     = $this->channel(Assistant::factory()->create());
        $this->app->instance(TenantLimitsInterface::class, new FakeTenantLimits(['monthly_active_contacts' => 100]));

        $this->refusal($channel, 'a', 2, now()->subDays(3));
        $this->refusal($channel, 'b', 1, now()->subDay());
        $this->refusal($channel, 'old', 9, now()->subDays(30));
        $this->refusal($channel, 'edge', 1, now()->subDays(29));
        $this->refusal($other, 'elsewhere', 5, now());

        $this->actingAs($this->admin())
            ->get($this->panelUrl("/assistant/{$assistant->getKey()}/dashboard"))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('contactLimit.people', 3)
                ->where('contactLimit.messages', 4)
                ->where('contactLimit.limit', 100)
                ->where('contactLimit.limitKnown', true)
                ->where('contactLimit.lastRefusedAt', now()->subDay()->utc()->toIso8601String())
                ->etc());
    }

    public function test_a_lifted_limit_still_shows_the_refusals_without_a_number(): void
    {
        $assistant = Assistant::factory()->create();
        $this->refusal($this->channel($assistant), 'a', 1, now());

        $this->actingAs($this->admin())
            ->get($this->panelUrl("/assistant/{$assistant->getKey()}/dashboard"))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('contactLimit.people', 1)
                ->where('contactLimit.limit', null)
                ->etc());
    }

    public function test_an_operator_that_cannot_answer_does_not_break_the_page(): void
    {
        $assistant = Assistant::factory()->create();
        $this->refusal($this->channel($assistant), 'a', 1, now());
        $this->app->instance(TenantLimitsInterface::class, new class () implements TenantLimitsInterface {
            public function limitFor(string $tenantId, string $key): ?int
            {
                throw new RuntimeException('operator is down');
            }
        });

        $this->actingAs($this->admin())
            ->get($this->panelUrl("/assistant/{$assistant->getKey()}/dashboard"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('contactLimit.people', 1)
                ->where('contactLimit.limit', null)
                ->where('contactLimit.limitKnown', false)
                ->etc());
    }

    public function test_without_refusals_there_is_no_contact_limit_card(): void
    {
        $assistant = Assistant::factory()->create();
        $this->refusal($this->channel($assistant), 'old', 1, now()->subDays(40));

        $this->actingAs($this->admin())
            ->get($this->panelUrl("/assistant/{$assistant->getKey()}/dashboard"))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('contactLimit', null)->etc());
    }

    public function test_a_user_who_may_not_see_contacts_gets_no_contact_limit_card(): void
    {
        $assistant = Assistant::factory()->create();
        $this->refusal($this->channel($assistant), 'a', 1, now());
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $user->assistants()->attach($assistant);

        $this->actingAs($user)
            ->get($this->panelUrl("/assistant/{$assistant->getKey()}/dashboard"))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('contactLimit', null)->etc());
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

    private function channel(Assistant $assistant): Channel
    {
        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => $assistant->tenant_id,
        ]));
    }

    private function refusal(Channel $channel, string $hash, int $attempts, DateTimeInterface $at): void
    {
        LimitRefusal::query()->create([
            'limit_key'       => 'monthly_active_contacts',
            'subject_hash'    => $hash,
            'channel_id'      => $channel->getKey(),
            'refused_on'      => $at->format('Y-m-d'),
            'attempts'        => $attempts,
            'last_refused_at' => $at,
        ]);
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
