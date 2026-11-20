<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Credentials\Models\Credential;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Domains\Credentials\CredentialsTestCase;

/**
 * The credentials screen in the admin panel: the list, create, change and delete, who may do which, and that a secret
 * reaches no response, flash message or session.
 */
final class CredentialsConsoleTest extends CredentialsTestCase
{
    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-0000000000ff';

    private const string NEW_SECRET = 'brand-new-secret-9';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_the_list_shows_the_credentials_of_the_tenant_without_a_secret(): void
    {
        $crm = $this->credential(['name' => 'CRM', 'allowed_hosts' => ['api.example.com', '*.example.org']]);
        $this->credential(['name' => 'Billing']);
        $this->credential(['name' => 'Foreign'], self::OTHER_TENANT_ID);

        $response = $this->actingAs($this->admin())
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Credentials/Index')
                ->where('navigation.mode', 'admin')
                ->where('table.meta.total', 2)
                ->where('table.rows.0.name', 'Billing')
                ->where('table.rows.1.id', $crm->id)
                ->where('table.rows.1.name', 'CRM')
                ->where('table.rows.1.type', 'bearer')
                ->where('table.rows.1.type_label', 'Bearer token')
                ->where('table.rows.1.allowed_hosts', ['api.example.com', '*.example.org'])
                ->where('table.rows.1.secret_changed_at', $crm->secret_changed_at->toIso8601String())
                ->where('table.rows.1.editUrl', "/admin/credentials/{$crm->id}/edit")
                ->where('can', ['create' => true, 'update' => true, 'delete' => true])
                ->where('urls.create', '/admin/credentials/create')
                ->etc());

        $this->assertSecretAbsent($response);
    }

    public function test_the_list_searches_by_name(): void
    {
        $this->credential(['name' => 'CRM production']);
        $this->credential(['name' => 'Billing']);

        $this->actingAs($this->admin())
            ->get($this->url('?search=crm'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.meta.total', 1)
                ->where('table.rows.0.name', 'CRM production')
                ->etc());
    }

    public function test_the_form_describes_every_type_from_the_registry(): void
    {
        $this->actingAs($this->admin())
            ->get($this->url('/create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Credentials/Create')
                ->where('types', fn ($types): bool => ['bearer', 'basic', 'api_key_header', 'api_key_query', 'oauth2_client_credentials'] === collect($types)->pluck('id')->all())
                ->where('types.0', [
                    'id'     => 'bearer',
                    'label'  => 'Bearer token',
                    'fields' => [['key' => 'token', 'label' => 'Token', 'secret' => true, 'required' => true, 'kind' => 'text', 'choices' => []]],
                ])
                ->where('types.4.fields.3', [
                    'key'      => 'client_auth',
                    'label'    => 'Send the client credentials',
                    'secret'   => false,
                    'required' => true,
                    'kind'     => 'select',
                    'choices'  => [
                        ['value' => 'basic', 'label' => 'In the Authorization header (Basic)'],
                        ['value' => 'body', 'label' => 'In the request body'],
                    ],
                ])
                ->where('urls.submit', '/admin/credentials')
                ->etc());
    }

    public function test_a_credential_is_created_and_the_secret_is_stored_encrypted(): void
    {
        $response = $this->actingAs($this->admin())
            ->post($this->url(), [
                'name'          => 'CRM',
                'type'          => 'api_key_header',
                'settings'      => ['header' => 'X-Api-Key', 'prefix' => ''],
                'secrets'       => ['key' => self::NEW_SECRET],
                'allowed_hosts' => ['api.example.com'],
            ])
            ->assertRedirect('/admin/credentials');

        $credential = Credential::query()->where('name', 'CRM')->firstOrFail();

        $this->assertSame('api_key_header', $credential->type);
        $this->assertSame(['header' => 'X-Api-Key'], $credential->settings);
        $this->assertSame(['key' => self::NEW_SECRET], $credential->secret);
        $this->assertSecretAbsent($response);

        // The toast is Inertia flash data, never the session.
        $this->assertNull(session('success'));
    }

    public function test_a_refused_create_flashes_no_secret_back(): void
    {
        $response = $this->actingAs($this->admin())
            ->from($this->url('/create'))
            ->post($this->url(), [
                'name'          => '',
                'type'          => 'bearer',
                'secrets'       => ['token' => self::NEW_SECRET],
                'allowed_hosts' => ['api.example.com'],
            ])
            ->assertRedirect($this->url('/create'))
            ->assertSessionHasErrors('name');

        $this->assertSame(0, Credential::query()->count());
        $this->assertSecretAbsent($response);
        $this->assertNull(session()->getOldInput('secrets'));
    }

    public function test_a_secret_the_type_refuses_is_not_echoed_in_the_error(): void
    {
        $response = $this->actingAs($this->admin())
            ->from($this->url('/create'))
            ->post($this->url(), [
                'name'          => 'CRM',
                'type'          => 'bearer',
                'secrets'       => ['token' => 'short'],
                'allowed_hosts' => ['api.example.com'],
            ])
            ->assertSessionHasErrors('secrets.token');

        $this->assertSame(0, Credential::query()->count());
        $this->assertStringNotContainsString('short', json_encode(session('errors')?->getBag('default')->toArray(), JSON_THROW_ON_ERROR));
        $this->assertNull(session()->getOldInput('secrets'));
        $this->assertSecretAbsent($response, 'short');
    }

    public function test_the_name_must_be_unique_within_the_tenant(): void
    {
        $this->credential(['name' => 'CRM']);
        $this->credential(['name' => 'Other tenant'], self::OTHER_TENANT_ID);

        $this->actingAs($this->admin())
            ->post($this->url(), ['name' => 'CRM', 'type' => 'bearer', 'secrets' => ['token' => self::NEW_SECRET], 'allowed_hosts' => ['api.example.com']])
            ->assertSessionHasErrors('name');

        $this->actingAs($this->admin())
            ->post($this->url(), ['name' => 'Other tenant', 'type' => 'bearer', 'secrets' => ['token' => self::NEW_SECRET], 'allowed_hosts' => ['api.example.com']])
            ->assertSessionHasNoErrors();
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post($this->url(), ['name' => 'X', 'type' => 'nope', 'allowed_hosts' => ['api.example.com']])
            ->assertSessionHasErrors('type');
    }

    public function test_the_edit_page_carries_no_secret_and_shows_when_it_changed(): void
    {
        $credential = $this->credential(['name' => 'CRM', 'type' => 'api_key_header', 'settings' => ['header' => 'X-Api-Key'], 'secret' => ['key' => self::SECRET]]);

        $response = $this->actingAs($this->admin())
            ->get($this->url("/{$credential->id}/edit"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/Credentials/Edit')
                ->where('credential', [
                    'id'                => $credential->id,
                    'name'              => 'CRM',
                    'type'              => 'api_key_header',
                    'type_label'        => 'API key in a header',
                    'settings'          => ['header' => 'X-Api-Key'],
                    'allowed_hosts'     => ['api.example.com'],
                    'secret_changed_at' => $credential->secret_changed_at->toIso8601String(),
                ])
                ->where('urls.submit', "/admin/credentials/{$credential->id}")
                ->etc());

        $this->assertSecretAbsent($response);
    }

    public function test_an_update_with_an_empty_secret_keeps_the_stored_one_and_ignores_a_type_change(): void
    {
        $credential = $this->credential(['name' => 'CRM', 'secret' => ['token' => self::SECRET]]);
        $changedAt  = $credential->secret_changed_at;

        $response = $this->actingAs($this->admin())
            ->put($this->url("/{$credential->id}"), [
                'name'          => 'CRM renamed',
                'type'          => 'basic',
                'secrets'       => ['token' => ''],
                'allowed_hosts' => ['api.example.com', 'api.example.org'],
            ])
            ->assertRedirect('/admin/credentials');

        $fresh = Credential::query()->findOrFail($credential->id);

        $this->assertSame('CRM renamed', $fresh->name);
        $this->assertSame('bearer', $fresh->type);
        $this->assertSame(['token' => self::SECRET], $fresh->secret);
        $this->assertSame(['api.example.com', 'api.example.org'], $fresh->allowed_hosts);
        $this->assertEquals($changedAt, $fresh->secret_changed_at);
        $this->assertSecretAbsent($response);
    }

    public function test_an_update_with_a_new_secret_replaces_it(): void
    {
        $credential = $this->credential(['secret' => ['token' => self::SECRET]]);

        $this->actingAs($this->admin())
            ->put($this->url("/{$credential->id}"), [
                'name'          => $credential->name,
                'secrets'       => ['token' => self::NEW_SECRET],
                'allowed_hosts' => ['api.example.com'],
            ])
            ->assertRedirect('/admin/credentials');

        $this->assertSame(['token' => self::NEW_SECRET], Credential::query()->findOrFail($credential->id)->secret);
    }

    public function test_a_refused_update_leaks_no_secret_in_the_response_or_the_session(): void
    {
        $credential = $this->credential(['secret' => ['token' => self::SECRET]]);

        $response = $this->actingAs($this->admin())
            ->from($this->url("/{$credential->id}/edit"))
            ->put($this->url("/{$credential->id}"), [
                'name'          => '',
                'secrets'       => ['token' => self::NEW_SECRET],
                'allowed_hosts' => ['api.example.com'],
            ])
            ->assertSessionHasErrors('name');

        $this->assertSecretAbsent($response);
        $this->assertNull(session()->getOldInput('secrets'));
        $this->assertSame(['token' => self::SECRET], Credential::query()->findOrFail($credential->id)->secret);

        // The page that follows the refusal shows the stored secret nowhere either.
        $page = $this->get($this->url("/{$credential->id}/edit"))->assertOk();
        $this->assertSecretAbsent($page);
        $this->assertSecretAbsent($page, self::NEW_SECRET);
    }

    public function test_a_credential_is_deleted_from_the_list_and_the_user_goes_back(): void
    {
        $credential = $this->credential();

        $response = $this->actingAs($this->admin())
            ->from($this->url('?search=abc'))
            ->delete($this->url("/{$credential->id}"))
            ->assertRedirect($this->url('?search=abc'));

        $this->assertNull(Credential::query()->find($credential->id));
        $this->assertSecretAbsent($response);
    }

    public function test_another_tenants_credential_is_not_found(): void
    {
        $foreign = $this->credential(['name' => 'Foreign'], self::OTHER_TENANT_ID);

        $this->actingAs($this->admin());

        $this->get($this->url("/{$foreign->id}/edit"))->assertNotFound();
        $this->put($this->url("/{$foreign->id}"), ['name' => 'Taken', 'allowed_hosts' => ['api.example.com']])->assertNotFound();
        $this->delete($this->url("/{$foreign->id}"))->assertNotFound();
        $this->get($this->url('/not-an-id/edit'))->assertNotFound();

        $this->assertSame('Foreign', Credential::query()->findOrFail($foreign->id)->name);
    }

    public function test_only_the_right_to_manage_credentials_opens_the_screen(): void
    {
        $credential = $this->credential();

        $allowed = User::factory()->create();
        $allowed->givePermissionTo(Permission::ManageCredentials->value);
        $this->actingAs($allowed)->get($this->url())->assertOk();

        // Managing the tenant's settings, or using credentials in flows, is not managing them.
        $denied = User::factory()->create();
        $denied->givePermissionTo(Permission::ManageSettings->value, Permission::UseCredentials->value, Permission::ViewSystem->value);
        $this->actingAs($denied);

        $this->get($this->url())->assertForbidden();
        $this->get($this->url('/create'))->assertForbidden();
        $this->get($this->url("/{$credential->id}/edit"))->assertForbidden();
        $this->post($this->url(), ['name' => 'X', 'type' => 'bearer', 'secrets' => ['token' => self::NEW_SECRET], 'allowed_hosts' => ['api.example.com']])->assertForbidden();
        $this->put($this->url("/{$credential->id}"), ['name' => 'X', 'allowed_hosts' => ['api.example.com']])->assertForbidden();
        $this->delete($this->url("/{$credential->id}"))->assertForbidden();

        $this->assertSame(1, Credential::query()->count());
    }

    public function test_the_admin_menu_lists_the_screen_only_with_the_right(): void
    {
        $allowed = User::factory()->create();
        $allowed->givePermissionTo(Permission::ManageCredentials->value);

        $this->actingAs($allowed)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation.groups', fn ($groups): bool => collect($groups)
                    ->flatMap(static fn (array $group): array => $group['items'])
                    ->contains(static fn (array $item): bool => 'console.admin.credentials.index' === $item['key']))
                ->etc());

        // Managing settings (which shows the System group) is not managing credentials.
        $other = User::factory()->create();
        $other->givePermissionTo(Permission::ManageUsers->value, Permission::ManageSettings->value, Permission::UseCredentials->value);

        $this->actingAs($other)
            ->get('/admin/users')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation.groups', fn ($groups): bool => ! collect($groups)
                    ->flatMap(static fn (array $group): array => $group['items'])
                    ->contains(static fn (array $item): bool => 'console.admin.credentials.index' === $item['key']))
                ->etc());
    }

    public function test_a_visitor_is_sent_to_the_sign_in(): void
    {
        $this->get($this->url())->assertRedirect(route('console.auth.login'));
    }

    /**
     * Neither the body, nor any flash or old input in the session holds the value.
     *
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     */
    private function assertSecretAbsent(TestResponse $response, string $secret = self::SECRET): void
    {
        $this->assertStringNotContainsString($secret, (string) $response->getContent());
        $this->assertStringNotContainsString($secret, json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($secret, json_encode($response->headers->all(), JSON_THROW_ON_ERROR));
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function url(string $suffix = ''): string
    {
        return $this->panelUrl("/admin/credentials{$suffix}");
    }
}
