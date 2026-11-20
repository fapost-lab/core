<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Repositories\FlowDraftRepository;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Domains\Credentials\CredentialsTestCase;

/**
 * Publishing what a draft changes against what runs now: a new saved credential in a `call` node needs
 * `UseCredentials`, a changed automatic trigger (any type but `message`) needs `ManageAutomations`; turning on a flow
 * with an automatic trigger needs `ManageAutomations` too. The content manager holds both, an admin passes everything.
 */
final class FlowAutomationPermissionsTest extends CredentialsTestCase
{
    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
    }

    public function test_publishing_a_new_credential_reference_needs_the_right_to_use_credentials(): void
    {
        $credential = $this->credential();
        $draft      = $this->draft($this->callNodes((string) $credential->id));

        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.credentials'));

        $this->publish($this->author(Permission::UseCredentials), $draft)->assertSuccessful();
    }

    public function test_republishing_the_same_credential_reference_or_dropping_it_needs_no_right(): void
    {
        $credential = $this->credential();
        $draft      = $this->draft($this->callNodes((string) $credential->id));
        $this->publish($this->author(Permission::UseCredentials), $draft)->assertSuccessful();

        // Same reference, the node edited around it.
        $draft->forceFill(['nodes' => $this->callNodes((string) $credential->id, 'GET https://api.example.com/other')])->save();
        $this->publish($this->author(), $draft)->assertSuccessful();

        $draft->forceFill(['nodes' => $this->callNodes(null)])->save();
        $this->publish($this->author(), $draft)->assertSuccessful();
    }

    public function test_switching_to_another_credential_needs_the_right(): void
    {
        $first  = $this->credential();
        $second = $this->credential();
        $draft  = $this->draft($this->callNodes((string) $first->id));
        $this->publish($this->author(Permission::UseCredentials), $draft)->assertSuccessful();

        $draft->forceFill(['nodes' => $this->callNodes((string) $second->id)])->save();

        $this->publish($this->author(), $draft)->assertForbidden();
    }

    public function test_adding_an_automatic_trigger_needs_the_right_to_manage_automations(): void
    {
        $draft = $this->draft(triggers: [$this->schedule((string) Str::uuid())]);

        $this->publish($this->author(), $draft)
            ->assertForbidden()
            ->assertJsonPath('message', __('builder.publish_denied.automations'));
        $this->assertSame(0, FlowTrigger::query()->count());

        $this->publish($this->author(Permission::ManageAutomations), $draft)->assertSuccessful();
        $this->assertSame(1, FlowTrigger::query()->count());
    }

    public function test_an_unchanged_automatic_trigger_republishes_without_the_right_and_a_change_to_it_does_not(): void
    {
        $id    = (string) Str::uuid();
        $draft = $this->draft(triggers: [$this->schedule($id)]);
        $this->publish($this->author(Permission::ManageAutomations), $draft)->assertSuccessful();

        // Nodes and a message trigger change; the automatic trigger does not.
        $draft->forceFill([
            'nodes'    => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'cancelled']]],
            'triggers' => [$this->schedule($id), $this->message((string) Str::uuid())],
        ])->save();
        $this->publish($this->author(), $draft)->assertSuccessful();
        $this->publish($this->author(), $draft)->assertSuccessful();

        foreach ([
            'config'   => [$this->schedule($id, ['cron' => '0 10 * * *', 'timezone' => 'UTC']), $this->message((string) Str::uuid())],
            'priority' => [$this->schedule($id, priority: 5)],
            'active'   => [$this->schedule($id, active: false)],
            'type'     => [$this->message($id)],
            'removed'  => [],
        ] as $change => $triggers) {
            $draft->forceFill(['triggers' => $triggers])->save();

            $this->publish($this->author(), $draft)->assertForbidden();
            $row = FlowTrigger::query()->findOrFail($id);
            $this->assertSame(['schedule', true, 100, '0 9 * * *'], [$row->type->value, $row->is_active, $row->priority, $row->config['cron'] ?? null], "A refused {$change} change must leave the live row alone.");
        }
    }

    public function test_a_draft_saved_after_the_publish_read_it_is_checked_as_saved(): void
    {
        $draft = $this->draft();

        // The builder's publish reads the draft, and a save lands right after that read, before the publish locks it:
        // the save adds an automatic trigger. What goes live must be checked, not what was read.
        $this->app->instance(FlowDraftRepositoryInterface::class, new class ($this->app->make(FlowDraftRepository::class), $this->schedule((string) Str::uuid())) implements FlowDraftRepositoryInterface {
            /**
             * @param  array<string, mixed>  $trigger
             */
            public function __construct(private readonly FlowDraftRepository $inner, private readonly array $trigger)
            {
            }

            public function findByFlowId(string $flowId): FlowDraft
            {
                $read = $this->inner->findByFlowId($flowId);
                FlowDraft::query()->whereKey($read->getKey())->firstOrFail()->forceFill(['triggers' => [$this->trigger]])->save();

                return $read;
            }
        });

        $this->publish($this->author(), $draft)->assertForbidden();
        $this->assertSame(0, FlowTrigger::query()->count());
    }

    public function test_the_content_manager_publishes_automations_and_credentials_but_does_not_manage_credentials(): void
    {
        $credential = $this->credential();
        $draft      = $this->draft($this->callNodes((string) $credential->id), [$this->schedule((string) Str::uuid())]);

        $manager = User::factory()->create();
        $manager->assignRole(RoleEnum::ContentManager->value);
        $manager->assistants()->attach($this->assistant);

        $this->publish($manager, $draft)->assertSuccessful();
        $this->actingAs($manager)->getJson('/builder/credentials')->assertOk();
        $this->assertFalse(Gate::forUser($manager)->allows('viewAny', $credential::class));
    }

    public function test_an_admin_passes_every_check(): void
    {
        $credential = $this->credential();
        $draft      = $this->draft($this->callNodes((string) $credential->id), [$this->schedule((string) Str::uuid())]);

        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);

        $this->publish($admin, $draft)->assertSuccessful();
    }

    public function test_turning_on_a_flow_with_an_automatic_trigger_needs_the_right_and_turning_it_off_does_not(): void
    {
        $automated = $this->draft(attributes: ['is_active' => false]);
        $this->liveRow($automated, 'schedule');
        $plain = $this->draft(attributes: ['is_active' => false]);
        $this->liveRow($plain, 'message');

        $editor = $this->author();

        $this->actingAs($editor)->patch($this->activityUrl($automated), ['active' => true])->assertForbidden();
        $this->assertFalse((bool) $automated->refresh()->is_active);

        $this->actingAs($editor)->patch($this->activityUrl($plain), ['active' => true])->assertRedirect();
        $this->assertTrue((bool) $plain->refresh()->is_active);

        $automated->forceFill(['is_active' => true])->save();
        $this->actingAs($editor)->patch($this->activityUrl($automated), ['active' => false])->assertRedirect();
        $this->assertFalse((bool) $automated->refresh()->is_active);

        $this->actingAs($this->author(Permission::ManageAutomations))->patch($this->activityUrl($automated), ['active' => true])->assertRedirect();
        $this->assertTrue((bool) $automated->refresh()->is_active);
    }

    public function test_reading_run_payloads_needs_both_session_and_payload_rights(): void
    {
        $session = new FlowSession();

        $sessions = User::factory()->create();
        $sessions->givePermissionTo(Permission::ViewFlowSessions->value);
        $payloads = User::factory()->create();
        $payloads->givePermissionTo(Permission::ViewRunPayloads->value);
        $both = User::factory()->create();
        $both->givePermissionTo(Permission::ViewFlowSessions->value, Permission::ViewRunPayloads->value);
        $manager = User::factory()->create();
        $manager->assignRole(RoleEnum::ContentManager->value);
        $analyst = User::factory()->create();
        $analyst->assignRole(RoleEnum::Analyst->value);

        $this->assertFalse(Gate::forUser($sessions)->allows('viewPayloads', $session));
        $this->assertFalse(Gate::forUser($payloads)->allows('viewPayloads', $session));
        $this->assertTrue(Gate::forUser($both)->allows('viewPayloads', $session));
        $this->assertTrue(Gate::forUser($manager)->allows('viewPayloads', $session));
        $this->assertFalse(Gate::forUser($analyst)->allows('viewPayloads', $session));
    }

    /**
     * A flow author who may publish, with an extra right when given.
     */
    private function author(?Permission $extra = null): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(array_values(array_filter([
            Permission::ManageAssistants->value,
            Permission::ManageFlowDefinitions->value,
            Permission::PublishFlow->value,
            $extra?->value,
        ])));
        $user->assistants()->attach($this->assistant);

        return $user;
    }

    private function publish(User $user, FlowDraft $draft): TestResponse
    {
        return $this->actingAs($user)->postJson("/builder/flows/{$draft->flow_id}/publish");
    }

    /**
     * @param  list<array<string, mixed>>|null  $nodes
     * @param  list<array<string, mixed>>|null  $triggers
     * @param  array<string, mixed>             $attributes
     */
    private function draft(?array $nodes = null, ?array $triggers = null, array $attributes = []): FlowDraft
    {
        return FlowDraft::factory()->create([
            'tenant_id'    => $this->tenantId,
            'assistant_id' => $this->assistant->getKey(),
            'nodes'        => $nodes ?? [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'        => null === $nodes ? [] : [['id' => 'e1', 'from' => 'call', 'to' => 'end', 'handle' => 'success']],
            'triggers'     => $triggers ?? [],
            ...$attributes,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function callNodes(?string $credentialId, string $target = 'GET https://api.example.com/me'): array
    {
        return [
            ['id' => 'call', 'type' => 'call', 'version' => 1, 'config' => array_filter([
                'transport'     => 'http',
                'target'        => $target,
                'credential_id' => $credentialId,
            ], static fn (mixed $value): bool => null !== $value)],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $config
     *
     * @return array<string, mixed>
     */
    private function schedule(string $id, ?array $config = null, int $priority = 100, bool $active = true): array
    {
        return ['id' => $id, 'type' => 'schedule', 'is_active' => $active, 'priority' => $priority, 'config' => $config ?? ['cron' => '0 9 * * *', 'timezone' => 'UTC']];
    }

    /**
     * @return array<string, mixed>
     */
    private function message(string $id): array
    {
        return ['id' => $id, 'type' => 'message', 'is_active' => true, 'priority' => 100, 'config' => ['phrases' => ['hello'], 'keywords' => []]];
    }

    private function liveRow(FlowDraft $draft, string $type): void
    {
        $row = new FlowTrigger();
        $row->forceFill([
            'id'           => (string) Str::uuid(),
            'tenant_id'    => $draft->tenant_id,
            'assistant_id' => $draft->assistant_id,
            'flow_id'      => $draft->flow_id,
            'type'         => $type,
            'is_active'    => true,
            'priority'     => 100,
            'config'       => 'schedule' === $type ? ['cron' => '0 9 * * *', 'timezone' => 'UTC'] : ['phrases' => ['hello'], 'keywords' => []],
        ])->save();
    }

    private function activityUrl(FlowDraft $draft): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/flows/{$draft->getKey()}/active");
    }
}
