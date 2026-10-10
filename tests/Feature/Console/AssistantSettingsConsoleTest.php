<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Settings\TenantSettings;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Concerns\SetsRecordLimits;

/**
 * The assistant's settings on the Inertia console: the form and what it offers, saving it in the canonical shape the
 * Filament page stored, its validation (including the domain's command rules), the locked default language, and a
 * flow created from inside the form for the default flow or for a command.
 */
final class AssistantSettingsConsoleTest extends InertiaConsoleTestCase
{
    use SetsRecordLimits;

    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-0000000000ff';

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        TenantSettings::fake(['content_base_language' => 'en', 'available_languages' => ['en', 'ru']]);
        $this->assistant = Assistant::factory()->create(['default_language' => 'en', 'available_countries' => []]);
    }

    public function test_the_form_shows_the_stored_settings_and_the_options(): void
    {
        $active   = $this->flow('Welcome');
        $inactive = $this->flow('Old one', ['is_active' => false]);
        $this->flow('Unused and off', ['is_active' => false]);
        $this->flow('Elsewhere', assistant: Assistant::factory()->create());
        $this->flow('Foreign', ['tenant_id' => self::OTHER_TENANT_ID], Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]));

        $this->assistant->update([
            'available_countries' => ['UA', 'PL'],
            'default_flow_id'     => $active->flow_id,
            'fallback_message'    => ['en' => 'Sorry', 'de' => 'Entschuldigung'],
            'busy_message'        => null,
            'commands'            => [
                ['command' => '/stop', 'type' => 'terminate_session', 'response' => 'Bye'],
                ['command' => '/old', 'type' => 'start_flow', 'flow_id' => $inactive->flow_id],
                ['command' => '/gone', 'type' => 'start_flow', 'flow_id' => (string) Str::uuid()],
            ],
            'settings' => ['tone' => 'formal', 'retries' => 3],
        ]);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Settings/Edit')
                // English, the tenant's languages, then a language a stored text still has.
                ->where('languages', ['en', 'ru', 'de'])
                ->where('settings.defaultLanguage', 'en')
                ->where('settings.availableCountries', ['UA', 'PL'])
                ->where('settings.defaultFlowId', $active->flow_id)
                ->where('settings.defaultFlowMissing', false)
                ->where('settings.fallbackMessage', [
                    ['locale' => 'en', 'text' => 'Sorry'],
                    ['locale' => 'ru', 'text' => ''],
                    ['locale' => 'de', 'text' => 'Entschuldigung'],
                ])
                ->where('settings.busyMessage.0', ['locale' => 'en', 'text' => ''])
                // The command without its slash; a legacy plain text goes to the first language.
                ->where('settings.commands.0.command', 'stop')
                ->where('settings.commands.0.response.0', ['locale' => 'en', 'text' => 'Bye'])
                ->where('settings.commands.1.flowId', $inactive->flow_id)
                ->where('settings.commands.1.flowMissing', false)
                ->where('settings.commands.2.flowId', null)
                ->where('settings.commands.2.flowMissing', true)
                ->where('settings.settings', [['key' => 'tone', 'value' => 'formal'], ['key' => 'retries', 'value' => '3']])
                // Active flows and the switched-off one a command points at, by name; nothing of other assistants.
                ->where('options.flows', [
                    ['value' => $inactive->flow_id, 'label' => 'Old one', 'active' => false],
                    ['value' => $active->flow_id, 'label' => 'Welcome', 'active' => true],
                ])
                ->where('options.commandTypes.1', [
                    'value' => 'start_flow',
                    'label' => 'Start flow',
                    'help'  => 'Ends the current dialog and starts the selected flow.',
                ])
                ->where('languageLocked', true)
                ->where('can.createFlow', true)
                ->where('urls', [
                    'submit'    => "/assistant/{$this->assistant->getKey()}/settings",
                    'storeFlow' => "/assistant/{$this->assistant->getKey()}/settings/flows",
                ])
                ->etc());
    }

    public function test_a_deleted_default_flow_is_shown_as_none_and_flagged(): void
    {
        $this->assistant->update(['default_flow_id' => (string) Str::uuid()]);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('settings.defaultFlowId', null)
                ->where('settings.defaultFlowMissing', true)
                ->where('languageLocked', false)
                ->etc());
    }

    public function test_the_settings_are_saved_in_their_canonical_shape(): void
    {
        $flow = $this->flow('Support');
        $from = $this->url('?tab=commands');

        $this->actingAs($this->admin())
            ->from($from)
            ->put($this->url(), $this->payload([
                'default_language'    => 'ru',
                'available_countries' => ['UA', 'DE'],
                'default_flow_id'     => null,
                'fallback_message'    => ['en' => 'Sorry', 'ru' => '  '],
                'busy_message'        => ['en' => null, 'ru' => null],
                'commands'            => [
                    ['command' => ' stop ', 'type' => 'terminate_session', 'response' => ['en' => 'Bye', 'ru' => ''], 'flow_id' => $flow->flow_id],
                    ['command' => '/go', 'type' => 'start_flow', 'flow_id' => $flow->flow_id, 'text' => ['en' => 'ignored']],
                    ['command' => 'help', 'type' => 'send_message', 'text' => ['ru' => 'Помощь'], 'response' => ['en' => 'ignored']],
                    ['command' => 'quiet', 'type' => 'terminate_session', 'response' => ['en' => '']],
                ],
                'settings' => [['key' => 'tone', 'value' => 'formal'], ['key' => 'empty', 'value' => null]],
            ]))
            ->assertRedirect($from)
            ->assertInertiaFlash('success', 'Settings saved');

        $assistant = $this->assistant->fresh();

        $this->assertNotNull($assistant);
        // The assistant has a flow, so its language stays.
        $this->assertSame('en', $assistant->default_language);
        $this->assertSame(['UA', 'DE'], $assistant->available_countries);
        $this->assertNull($assistant->default_flow_id);
        $this->assertSame(['en' => 'Sorry'], $assistant->fallback_message);
        $this->assertNull($assistant->busy_message);
        $this->assertSame($this->canonical([
            ['command' => '/stop', 'type' => 'terminate_session', 'response' => ['en' => 'Bye']],
            ['command' => '/go', 'type' => 'start_flow', 'flow_id' => $flow->flow_id],
            ['command' => '/help', 'type' => 'send_message', 'text' => ['ru' => 'Помощь']],
            ['command' => '/quiet', 'type' => 'terminate_session'],
        ]), $this->canonical($assistant->commands));
        $this->assertEqualsCanonicalizing(['tone' => 'formal', 'empty' => ''], $assistant->settings);
    }

    public function test_untouched_advanced_settings_keep_their_stored_type(): void
    {
        $stored = ['retries' => 3, 'enabled' => true, 'limits' => ['x' => 1], 'none' => null, 'tone' => 'formal'];
        $this->assistant->update(['settings' => $stored]);

        // Rows sent back as the form showed them: non-strings as JSON, null as empty (which reaches the server as null).
        $shown = [
            ['key' => 'retries', 'value' => '3'],
            ['key' => 'enabled', 'value' => 'true'],
            ['key' => 'limits', 'value' => '{"x":1}'],
            ['key' => 'none', 'value' => ''],
            ['key' => 'tone', 'value' => 'formal'],
        ];

        $this->actingAs($this->admin())
            ->put($this->url(), $this->payload(['settings' => $shown]))
            ->assertSessionHasNoErrors();

        $this->assertSame($stored, $this->assistant->fresh()?->settings);

        $shown[0]['value'] = '5';
        $this->put($this->url(), $this->payload(['settings' => $shown]))->assertSessionHasNoErrors();

        $this->assertSame(['retries' => '5', 'enabled' => true, 'limits' => ['x' => 1], 'none' => null, 'tone' => 'formal'], $this->assistant->fresh()?->settings);
    }

    public function test_an_empty_advanced_settings_row_is_dropped(): void
    {
        $this->actingAs($this->admin())
            ->put($this->url(), $this->payload(['settings' => [['key' => 'tone', 'value' => 'formal'], ['key' => '', 'value' => ''], ['key' => null, 'value' => null]]]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['tone' => 'formal'], $this->assistant->fresh()?->settings);

        // A value without a key is still an error.
        $this->put($this->url(), $this->payload(['settings' => [['key' => '', 'value' => 'orphan']]]))->assertSessionHasErrors('settings.0.key');
    }

    public function test_a_command_of_unknown_type_asks_for_one(): void
    {
        $this->assistant->update(['commands' => [['command' => '/legacy', 'type' => 'launch_rocket']]]);

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('settings.commands.0.type', '')->etc());

        $this->put($this->url(), $this->payload(['commands' => [['command' => 'legacy', 'type' => '']]]))->assertSessionHasErrors('commands.0.type');
    }

    public function test_the_default_language_is_kept_once_the_assistant_has_flows(): void
    {
        $this->flow('Existing');

        $this->actingAs($this->admin())
            ->put($this->url(), $this->payload(['default_language' => 'ru']))
            ->assertSessionHasNoErrors();

        $this->assertSame('en', $this->assistant->fresh()?->default_language);

        // Locked, it is not even validated: a disabled field may send nothing.
        $this->put($this->url(), $this->payload(['default_language' => null]))->assertSessionHasNoErrors();
    }

    public function test_the_fields_are_validated(): void
    {
        $foreign      = $this->flow('Elsewhere', assistant: Assistant::factory()->create());
        $otherTenant  = $this->flow('Foreign', ['tenant_id' => self::OTHER_TENANT_ID], Assistant::factory()->create(['tenant_id' => self::OTHER_TENANT_ID]));
        $startCommand = static fn (?string $flowId): array => [['command' => 'go', 'type' => 'start_flow', 'flow_id' => $flowId]];

        $this->actingAs($this->admin());

        $this->put($this->url(), $this->payload(['default_language' => null]))->assertSessionHasErrors('default_language');
        $this->put($this->url(), $this->payload(['default_language' => 'xx']))->assertSessionHasErrors('default_language');
        $this->put($this->url(), $this->payload(['available_countries' => ['ZZ']]))->assertSessionHasErrors('available_countries.0');
        $this->put($this->url(), $this->payload(['default_flow_id' => (string) Str::uuid()]))->assertSessionHasErrors('default_flow_id');
        $this->put($this->url(), $this->payload(['default_flow_id' => $foreign->flow_id]))->assertSessionHasErrors('default_flow_id');
        $this->put($this->url(), $this->payload(['default_flow_id' => $otherTenant->flow_id]))->assertSessionHasErrors('default_flow_id');
        $this->put($this->url(), $this->payload(['fallback_message' => ['xx' => 'Hello']]))->assertSessionHasErrors('fallback_message.xx');
        $this->put($this->url(), $this->payload(['commands' => [['command' => str_repeat('a', 65), 'type' => 'terminate_session']]]))->assertSessionHasErrors('commands.0.command');
        $this->put($this->url(), $this->payload(['commands' => [['command' => '', 'type' => 'terminate_session']]]))->assertSessionHasErrors('commands.0.command');
        $this->put($this->url(), $this->payload(['commands' => [['command' => 'x', 'type' => 'launch']]]))->assertSessionHasErrors('commands.0.type');
        $this->put($this->url(), $this->payload(['commands' => $startCommand(null)]))->assertSessionHasErrors('commands.0.flow_id');
        $this->put($this->url(), $this->payload(['commands' => $startCommand($foreign->flow_id)]))->assertSessionHasErrors('commands.0.flow_id');
        $this->put($this->url(), $this->payload(['settings' => [['key' => 'a', 'value' => '1'], ['key' => 'a', 'value' => '2']]]))->assertSessionHasErrors('settings.1.key');

        $this->assertSame([], $this->assistant->fresh()?->commands ?? []);
    }

    public function test_the_commands_must_pass_the_domain_rules(): void
    {
        $this->actingAs($this->admin());

        $this->put($this->url(), $this->payload(['commands' => [
            ['command' => 'stop', 'type' => 'terminate_session'],
            ['command' => '/stop', 'type' => 'send_message', 'text' => ['en' => 'x']],
        ]]))->assertSessionHasErrors(['commands' => "Commands are invalid: Duplicate command '/stop' (also in commands[0])."]);

        $this->put($this->url(), $this->payload(['commands' => [['command' => 'two words', 'type' => 'terminate_session']]]))
            ->assertSessionHasErrors('commands');

        // A built-in may change its reply, not its action.
        $this->put($this->url(), $this->payload(['commands' => [['command' => 'reset', 'type' => 'send_message', 'text' => ['en' => 'x']]]]))
            ->assertSessionHasErrors('commands');

        $this->assertSame([], $this->assistant->fresh()?->commands ?? []);
    }

    public function test_a_new_flow_becomes_the_default_flow_and_the_form_is_saved_with_it(): void
    {
        $response = $this->actingAs($this->admin())
            ->post($this->url('/flows'), $this->payload([
                'default_language' => 'ru',
                'fallback_message' => ['en' => 'Sorry'],
                'new_flow_name'    => 'Welcome',
                'new_flow_target'  => 'default',
            ]), ['X-Inertia' => 'true']);

        $flow = FlowDraft::query()->where('name', 'Welcome')->sole();

        $response->assertStatus(409)->assertHeader('X-Inertia-Location', "/builder/flows/{$flow->flow_id}");

        $assistant = $this->assistant->fresh();

        $this->assertSame((string) $this->assistant->getKey(), $flow->assistant_id);
        $this->assertTrue($flow->is_public);
        $this->assertSame($flow->flow_id, $assistant?->default_flow_id);
        $this->assertSame(['en' => 'Sorry'], $assistant?->fallback_message);
        // The language was checked before the flow existed, as when saving.
        $this->assertSame('ru', $assistant?->default_language);
    }

    public function test_a_new_flow_is_the_flow_of_the_command_it_was_made_for(): void
    {
        $this->actingAs($this->admin())
            ->post($this->url('/flows'), $this->payload([
                'commands' => [
                    ['command' => 'stop', 'type' => 'terminate_session'],
                    ['command' => 'go', 'type' => 'start_flow', 'flow_id' => null],
                ],
                'new_flow_name'   => 'Onboarding',
                'new_flow_target' => 'command',
                'command_index'   => 1,
            ]), ['X-Inertia' => 'true'])
            ->assertStatus(409);

        $flow = FlowDraft::query()->where('name', 'Onboarding')->sole();

        $this->assertSame($this->canonical([
            ['command' => '/stop', 'type' => 'terminate_session'],
            ['command' => '/go', 'type' => 'start_flow', 'flow_id' => $flow->flow_id],
        ]), $this->canonical($this->assistant->fresh()?->commands));
        $this->assertNull($this->assistant->fresh()?->default_flow_id);
    }

    public function test_a_new_flow_needs_a_name_and_a_command_that_starts_a_flow(): void
    {
        $this->actingAs($this->admin());

        $this->post($this->url('/flows'), $this->payload(['new_flow_name' => '', 'new_flow_target' => 'default']))->assertSessionHasErrors('new_flow_name');
        $this->post($this->url('/flows'), $this->payload(['new_flow_name' => 'X', 'new_flow_target' => 'other']))->assertSessionHasErrors('new_flow_target');
        $this->post($this->url('/flows'), $this->payload(['new_flow_name' => 'X', 'new_flow_target' => 'command']))->assertSessionHasErrors('command_index');
        $this->post($this->url('/flows'), $this->payload([
            'commands'        => [['command' => 'stop', 'type' => 'terminate_session']],
            'new_flow_name'   => 'X',
            'new_flow_target' => 'command',
            'command_index'   => 0,
        ]))->assertSessionHasErrors('command_index');
        // Another command that starts a flow still needs its own.
        $this->post($this->url('/flows'), $this->payload([
            'commands'        => [['command' => 'a', 'type' => 'start_flow'], ['command' => 'b', 'type' => 'start_flow']],
            'new_flow_name'   => 'X',
            'new_flow_target' => 'command',
            'command_index'   => 1,
        ]))->assertSessionHasErrors('commands.0.flow_id')->assertSessionDoesntHaveErrors('commands.1.flow_id');

        $this->assertSame(0, FlowDraft::query()->count());
    }

    public function test_at_the_flow_limit_nothing_is_written_and_the_refusal_is_a_toast(): void
    {
        $this->limitRecords('flows', 0);
        $from = $this->url();

        $this->actingAs($this->admin())
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.createFlow', false)->where('limit.reached', true)->etc());

        $this->from($from)
            ->post($this->url('/flows'), $this->payload([
                'fallback_message' => ['en' => 'Changed'],
                'new_flow_name'    => 'Late',
                'new_flow_target'  => 'default',
            ]))
            ->assertRedirect($from)
            ->assertInertiaFlash('error', 'Flow limit reached. Flows limit reached: 0 of 0.');

        $this->assertSame(0, FlowDraft::query()->count());
        $this->assertNull($this->assistant->fresh()?->fallback_message);
    }

    public function test_the_screen_needs_the_settings_permission(): void
    {
        $this->actingAs($this->userWith([Permission::ManageFlowDefinitions]));

        $this->get($this->url())->assertForbidden();
        $this->put($this->url(), $this->payload())->assertForbidden();
        $this->post($this->url('/flows'), $this->payload(['new_flow_name' => 'X', 'new_flow_target' => 'default']))->assertForbidden();
    }

    public function test_creating_a_flow_also_needs_the_flow_permission(): void
    {
        $this->actingAs($this->userWith([Permission::ManageAssistantSettings]));

        $this->get($this->url())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('can.createFlow', false)->etc());
        $this->put($this->url(), $this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $this->post($this->url('/flows'), $this->payload(['new_flow_name' => 'X', 'new_flow_target' => 'default']))->assertForbidden();

        $this->assertSame(0, FlowDraft::query()->count());
    }

    public function test_an_assistant_the_user_is_not_assigned_to_is_not_found(): void
    {
        $other = Assistant::factory()->create();

        $this->actingAs($this->userWith([Permission::ManageAssistantSettings]))
            ->get($this->panelUrl("/assistant/{$other->getKey()}/settings"))
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'default_language'    => 'en',
            'available_countries' => [],
            'default_flow_id'     => null,
            'fallback_message'    => [],
            'busy_message'        => [],
            'commands'            => [],
            'settings'            => [],
            ...$overrides,
        ];
    }

    /**
     * Sorts keys at every level: `jsonb` stores an object's keys in its own order, the list order stays.
     */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn (mixed $item): mixed => $this->canonical($item), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function url(string $suffix = ''): string
    {
        return $this->panelUrl("/assistant/{$this->assistant->getKey()}/settings{$suffix}");
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function flow(string $name, array $attributes = [], ?Assistant $assistant = null): FlowDraft
    {
        return FlowDraft::factory()->create([
            'assistant_id' => ($assistant ?? $this->assistant)->getKey(),
            'name'         => $name,
            'is_active'    => true,
            ...$attributes,
        ]);
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
        // Opening an assistant's console needs ManageAssistants; the screen asks for its own permissions on top.
        $user->givePermissionTo([Permission::ManageAssistants->value, ...array_map(static fn (Permission $permission): string => $permission->value, $permissions)]);
        $user->assistants()->attach($this->assistant);

        return $user;
    }
}
