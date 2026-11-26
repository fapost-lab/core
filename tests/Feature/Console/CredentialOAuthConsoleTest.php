<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Credentials\CredentialWriter;
use App\Domains\Credentials\EloquentCredentialStore;
use App\Domains\Credentials\Models\Credential;
use App\Domains\Credentials\Models\CredentialOAuthGrant;
use App\Domains\Credentials\OAuth2\OAuth2Consent;
use App\Domains\Credentials\OAuth2\OAuth2GrantStatus;
use App\Domains\Flow\Call\Egress\EgressGuardMiddleware;
use App\Domains\Flow\Call\Egress\GuardedHttpClient;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use ReflectionProperty;
use Tests\Feature\Domains\Credentials\CredentialsTestCase;
use Tests\Support\FakeTenantAccessMode;
use Tests\Support\RecordingConnectionResolver;

/**
 * Connecting an `oauth2_authorization_code` credential from the console: the authorization URL with state and PKCE,
 * the callback and every way it refuses, disconnecting, who may do it, and that no code or token reaches a response,
 * the session or the audit.
 */
final class CredentialOAuthConsoleTest extends CredentialsTestCase
{
    private const string TOKEN_URL = 'https://auth.example.com/oauth/token';

    private const string ACCESS = 'access-token-from-consent';

    private const string REFRESH = 'refresh-token-from-consent';

    private const string CODE = 'authorization-code-123';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([GuardedHttpClient::class, EgressGuardMiddleware::class] as $class) {
            $this->app->forgetInstance($class);
        }

        $this->seed(TenantAclSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(RoleEnum::Admin->value);
    }

    public function test_the_edit_page_shows_the_connection_and_the_callback_url_without_a_token(): void
    {
        $credential = $this->oauthCredential();
        $bearer     = $this->credential();

        $this->actingAs($this->admin)
            ->get($this->panelUrl("/admin/credentials/{$credential->id}/edit"))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('oauth.status', 'not_connected')
                ->where('oauth.connected_at', null)
                ->where('oauth.callback_url', route('console.admin.credentials.oauth.callback'))
                ->where('oauth.urls.connect', "/admin/credentials/{$credential->id}/oauth/connect")
                ->where('oauth.urls.disconnect', "/admin/credentials/{$credential->id}/oauth")
                ->etc());

        $this->grant($credential, ['connected_by' => $this->admin->id, 'access_token' => self::ACCESS, 'refresh_token' => self::REFRESH]);

        $response = $this->get($this->panelUrl("/admin/credentials/{$credential->id}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('oauth.status', 'connected')
                ->where('oauth.connected_by_name', $this->admin->name)
                ->etc());

        $this->assertNoTokens($response);

        $this->get($this->panelUrl("/admin/credentials/{$bearer->id}/edit"))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('oauth', null)->etc());
    }

    public function test_connect_sends_the_browser_to_the_provider_with_state_and_pkce(): void
    {
        $credential = $this->oauthCredential(['settings' => [
            ...$this->oauthCredential()->settings,
            'authorize_params' => 'access_type=offline&prompt=consent',
        ]]);

        $location = $this->connect($credential);

        $this->assertStringStartsWith('https://login.example.net/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $attempt = session(OAuth2Consent::SESSION_KEY)[$query['state']] ?? null;
        $this->assertIsArray($attempt);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('client-1', $query['client_id']);
        $this->assertSame(route('console.admin.credentials.oauth.callback'), $query['redirect_uri']);
        $this->assertSame('read write', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(OAuth2Consent::challenge($attempt['code_verifier']), $query['code_challenge']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame($credential->id, $attempt['credential_id']);
        $this->assertStringNotContainsString(self::SECRET, $location);
    }

    public function test_the_callback_exchanges_the_code_stores_the_grant_and_records_it(): void
    {
        Http::fake([self::TOKEN_URL => Http::response([
            'access_token'  => self::ACCESS,
            'refresh_token' => self::REFRESH,
            'expires_in'    => 3600,
            'scope'         => 'read',
        ])]);
        $credential = $this->oauthCredential(['name' => 'CRM']);
        $state      = $this->stateOf($this->connect($credential));
        $verifier   = session(OAuth2Consent::SESSION_KEY)[$state]['code_verifier'];

        $response = $this->get($this->callbackUrl(['state' => $state, 'code' => self::CODE]))
            ->assertRedirect("/admin/credentials/{$credential->id}/edit");

        Http::assertSent(static fn (Request $request): bool => self::TOKEN_URL === $request->url()
            && 'authorization_code' === $request['grant_type']
            && self::CODE === $request['code']
            && route('console.admin.credentials.oauth.callback') === $request['redirect_uri']
            && $verifier === $request['code_verifier']);

        $grant = CredentialOAuthGrant::query()->findOrFail($credential->id);
        $this->assertSame(OAuth2GrantStatus::Connected, $grant->status);
        $this->assertSame(self::ACCESS, $grant->access_token);
        $this->assertSame(self::REFRESH, $grant->refresh_token);
        $this->assertSame('read', $grant->granted_scope);
        $this->assertSame($this->admin->id, $grant->connected_by);

        $entry = ActivityEntry::query()->sole();
        $this->assertSame('credential.connected', $entry->description);
        $this->assertSame('CRM', $entry->subject_label);
        $this->assertSame(['type' => 'oauth2_authorization_code', 'granted_scope' => 'read'], $entry->properties?->toArray());

        $this->assertNoTokens($response);
        $this->assertStringNotContainsString(self::CODE, json_encode([$entry->getAttributes(), session()->all()], JSON_THROW_ON_ERROR));
        $this->assertSame([], session(OAuth2Consent::SESSION_KEY));
    }

    public function test_a_state_works_once(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => self::ACCESS])]);
        $credential = $this->oauthCredential();
        $state      = $this->stateOf($this->connect($credential));

        $this->get($this->callbackUrl(['state' => $state, 'code' => self::CODE]))->assertRedirect("/admin/credentials/{$credential->id}/edit");
        $this->get($this->callbackUrl(['state' => $state, 'code' => self::CODE]))->assertRedirect('/admin/credentials');
        $this->get($this->callbackUrl(['state' => 'unknown-state', 'code' => self::CODE]))->assertRedirect('/admin/credentials');
        $this->get($this->callbackUrl(['code' => self::CODE]))->assertRedirect('/admin/credentials');

        Http::assertSentCount(1);
    }

    public function test_an_expired_attempt_or_another_users_callback_is_refused(): void
    {
        Http::fake();
        $credential = $this->oauthCredential();

        $state = $this->stateOf($this->connect($credential));
        $this->travel(OAuth2Consent::TTL_SECONDS + 1)->seconds();
        $this->get($this->callbackUrl(['state' => $state, 'code' => self::CODE]))->assertRedirect('/admin/credentials');
        $this->travelBack();

        $state = $this->stateOf($this->connect($credential));
        $other = User::factory()->create();
        $other->assignRole(RoleEnum::Admin->value);
        $this->actingAs($other)->get($this->callbackUrl(['state' => $state, 'code' => self::CODE]))->assertRedirect('/admin/credentials');

        Http::assertNothingSent();
        $this->assertSame(0, CredentialOAuthGrant::query()->count());
    }

    public function test_a_credential_changed_or_deleted_since_the_start_is_refused(): void
    {
        Http::fake();
        $changed = $this->oauthCredential();
        $state   = $this->stateOf($this->connect($changed));

        $this->travel(1)->seconds();
        $changed->update(['name' => 'Renamed']);

        $this->get($this->callbackUrl(['state' => $state, 'code' => self::CODE]))->assertRedirect("/admin/credentials/{$changed->id}/edit");

        $deleted = $this->oauthCredential();
        $state   = $this->stateOf($this->connect($deleted));
        $deleted->delete();

        $this->get($this->callbackUrl(['state' => $state, 'code' => self::CODE]))->assertRedirect('/admin/credentials');

        Http::assertNothingSent();
        $this->assertSame(0, CredentialOAuthGrant::query()->count());
    }

    public function test_a_client_change_or_a_delete_during_the_exchange_stores_no_grant(): void
    {
        $writer     = $this->app->make(CredentialWriter::class);
        $credential = $this->oauthCredential();
        $state      = $this->stateOf($this->connect($credential));

        // The client is changed while the code is being exchanged: the grant would be for the old client.
        Http::fake([self::TOKEN_URL => function () use ($writer, $credential) {
            $this->travel(1)->seconds();
            $writer->update($credential->fresh() ?? $credential, ['name' => $credential->name, 'settings' => [...$credential->settings, 'client_id' => 'client-2'], 'allowed_hosts' => ['api.example.com', 'auth.example.com']]);

            return Http::response(['access_token' => self::ACCESS, 'refresh_token' => self::REFRESH]);
        }]);

        $this->get($this->callbackUrl(['state' => $state, 'code' => self::CODE]))->assertRedirect("/admin/credentials/{$credential->id}/edit");
        $this->assertSame(trans('console.credentials.oauth.flash.stale'), $this->flashed('error'));
        $this->assertSame(0, CredentialOAuthGrant::query()->count());

        // The credential is deleted while the code is being exchanged: refused, not a foreign key error.
        $deleted = $this->oauthCredential();
        $state   = $this->stateOf($this->connect($deleted));

        Http::fake([self::TOKEN_URL => function () use ($writer, $deleted) {
            $writer->delete($deleted);

            return Http::response(['access_token' => self::ACCESS, 'refresh_token' => self::REFRESH]);
        }]);

        $this->get($this->callbackUrl(['state' => $state, 'code' => self::CODE]))->assertRedirect('/admin/credentials');
        $this->assertSame(trans('console.credentials.oauth.flash.stale'), $this->flashed('error'));
        $this->assertSame(0, CredentialOAuthGrant::query()->count());
        $this->assertSame(0, ActivityEntry::query()->where('description', 'credential.connected')->count());
    }

    public function test_a_guest_or_a_refused_callback_leaves_no_code_or_state_in_the_session(): void
    {
        $state = 'state-of-the-callback-' . str_repeat('s', 21);

        $this->get($this->callbackUrl(['state' => $state, 'code' => self::CODE]))->assertRedirect(route('console.auth.login'));
        $this->assertNotInSession([self::CODE, $state]);

        $denied = User::factory()->create();
        $denied->givePermissionTo(Permission::UseCredentials->value, Permission::ManageSettings->value);

        $this->actingAs($denied)->get($this->callbackUrl(['state' => $state, 'code' => self::CODE]))->assertForbidden();
        $this->assertNotInSession([self::CODE, $state]);
    }

    public function test_a_provider_error_shows_its_code_and_nothing_of_its_description(): void
    {
        Http::fake();
        $credential = $this->oauthCredential();
        $state      = $this->stateOf($this->connect($credential));

        $response = $this->get($this->callbackUrl(['state' => $state, 'error' => 'access_denied', 'error_description' => 'User said no to client secret-ish']))
            ->assertRedirect("/admin/credentials/{$credential->id}/edit");

        $this->assertStringNotContainsString('User said no', json_encode(session()->all(), JSON_THROW_ON_ERROR) . $response->getContent());
        $this->assertSame(trans('console.credentials.oauth.flash.provider_error', ['code' => 'access_denied']), $this->flashed('error'));

        Http::assertNothingSent();
    }

    public function test_a_failed_exchange_stores_nothing_and_leaks_no_body(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_grant', 'error_description' => 'code authorization-code-123 used'], 400)]);
        $credential = $this->oauthCredential();
        $state      = $this->stateOf($this->connect($credential));

        $response = $this->get($this->callbackUrl(['state' => $state, 'code' => self::CODE]))
            ->assertRedirect("/admin/credentials/{$credential->id}/edit");

        $this->assertSame(trans('console.credentials.oauth.flash.exchange_failed'), $this->flashed('error'));
        $this->assertStringNotContainsString(self::CODE, json_encode(session()->all(), JSON_THROW_ON_ERROR) . $response->getContent());
        $this->assertSame(0, CredentialOAuthGrant::query()->count());
        $this->assertSame(0, ActivityEntry::query()->count());
    }

    public function test_a_grant_whose_tokens_cannot_be_decrypted_is_reconnected_without_disconnecting_first(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => self::ACCESS, 'refresh_token' => self::REFRESH, 'expires_in' => 3600])]);
        $credential = $this->oauthCredential();
        $this->grant($credential, ['status' => OAuth2GrantStatus::ReconnectNeeded]);
        // Written with another application key: the stored tokens cannot be decrypted any more.
        DB::table('credential_oauth_grants')->update(['access_token' => 'garbage', 'refresh_token' => 'garbage']);
        $state = $this->stateOf($this->connect($credential));

        $this->get($this->callbackUrl(['state' => $state, 'code' => self::CODE]))
            ->assertRedirect("/admin/credentials/{$credential->id}/edit");

        $grant = CredentialOAuthGrant::query()->sole();
        $this->assertSame(OAuth2GrantStatus::Connected, $grant->status);
        $this->assertSame(self::ACCESS, $grant->access_token);
        $this->assertSame(self::REFRESH, $grant->refresh_token);
        $this->assertSame($this->admin->id, $grant->connected_by);
        $this->assertSame('credential.connected', ActivityEntry::query()->sole()->description);
    }

    public function test_disconnect_deletes_the_grant_and_records_it(): void
    {
        $credential = $this->oauthCredential();
        $this->grant($credential);

        $this->actingAs($this->admin)
            ->delete($this->panelUrl("/admin/credentials/{$credential->id}/oauth"))
            ->assertRedirect("/admin/credentials/{$credential->id}/edit");

        $this->assertSame(0, CredentialOAuthGrant::query()->count());
        $this->assertSame('credential.disconnected', ActivityEntry::query()->sole()->description);
    }

    public function test_disconnect_opens_its_transaction_on_the_connection_current_when_it_is_called(): void
    {
        $credential = $this->oauthCredential();
        $this->grant($credential);

        $resolver = new RecordingConnectionResolver($this->app->make('db'));
        $this->app->instance(ConnectionResolverInterface::class, $resolver);

        $consent = $this->app->make(OAuth2Consent::class);

        $this->assertSame([], $resolver->resolved, 'A connection captured when the consent is built would outlive a tenant switch.');

        // A tenant switch after the consent was built: another default connection, over the same database here.
        config(['database.connections.switched' => config('database.connections.' . DB::getDefaultConnection())]);
        $switched = DB::connection('switched');
        $switched->setPdo(DB::connection()->getPdo());
        // The test's own transaction is open on that shared PDO already: the switched connection nests in it.
        (new ReflectionProperty($switched, 'transactions'))->setValue($switched, DB::connection()->transactionLevel());
        DB::setDefaultConnection('switched');

        $this->assertTrue($consent->disconnect($credential));

        $this->assertSame(['switched'], $resolver->resolved);
        $this->assertSame(0, CredentialOAuthGrant::query()->count());
    }

    public function test_only_an_oauth2_authorization_code_credential_connects(): void
    {
        $bearer = $this->credential();

        $this->actingAs($this->admin);
        $this->post($this->panelUrl("/admin/credentials/{$bearer->id}/oauth/connect"))->assertNotFound();
        $this->delete($this->panelUrl("/admin/credentials/{$bearer->id}/oauth"))->assertNotFound();
        $this->post($this->panelUrl('/admin/credentials/not-an-id/oauth/connect'))->assertNotFound();
    }

    public function test_only_the_right_to_manage_credentials_connects(): void
    {
        Http::fake();
        $credential = $this->oauthCredential();
        $this->grant($credential);
        $state = $this->stateOf($this->connect($credential));

        $denied = User::factory()->create();
        $denied->givePermissionTo(Permission::UseCredentials->value, Permission::ManageSettings->value);
        $this->actingAs($denied);

        $this->post($this->panelUrl("/admin/credentials/{$credential->id}/oauth/connect"))->assertForbidden();
        $this->delete($this->panelUrl("/admin/credentials/{$credential->id}/oauth"))->assertForbidden();
        $this->get($this->callbackUrl(['state' => $state, 'code' => self::CODE]))->assertForbidden();

        $this->assertSame(1, CredentialOAuthGrant::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_stopped_tenant_cannot_connect(): void
    {
        Http::fake();
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped());
        $credential = $this->oauthCredential();
        $this->grant($credential);
        $attempt = [
            'tenant_id'     => $this->tenantId,
            'credential_id' => $credential->id,
            'version'       => EloquentCredentialStore::versionOf($credential),
            'user_id'       => $this->admin->id,
            'redirect_uri'  => route('console.admin.credentials.oauth.callback'),
            'code_verifier' => str_repeat('v', 64),
            'expires_at'    => now()->addMinutes(5)->getTimestamp(),
        ];

        $this->actingAs($this->admin)->withSession([OAuth2Consent::SESSION_KEY => ['state-1' => $attempt]]);

        $this->postJson($this->panelUrl("/admin/credentials/{$credential->id}/oauth/connect"))->assertStatus(423);
        $this->deleteJson($this->panelUrl("/admin/credentials/{$credential->id}/oauth"))->assertStatus(423);
        $this->get($this->callbackUrl(['state' => 'state-1', 'code' => self::CODE]))->assertRedirect('/admin/credentials');
        $this->assertSame(trans('console.credentials.oauth.flash.tenant_stopped'), $this->flashed('error'));

        Http::assertNothingSent();
        $this->assertSame(OAuth2GrantStatus::Connected, CredentialOAuthGrant::query()->sole()->status);
        $this->assertSame(0, ActivityEntry::query()->count());
    }

    public function test_changing_the_client_drops_the_grant_and_renaming_does_not(): void
    {
        $writer     = $this->app->make(CredentialWriter::class);
        $credential = $this->oauthCredential();
        $this->grant($credential);

        $writer->update($credential, ['name' => 'Renamed', 'settings' => $credential->settings, 'allowed_hosts' => ['auth.example.com', 'api.example.com']]);
        $this->assertSame(1, CredentialOAuthGrant::query()->count());

        $writer->update($credential->fresh() ?? $credential, ['name' => 'Renamed', 'settings' => [...$credential->settings, 'client_id' => 'client-2'], 'allowed_hosts' => ['api.example.com', 'auth.example.com']]);
        $this->assertSame(0, CredentialOAuthGrant::query()->count());
        $this->assertEquals(['secret_replaced' => false, 'grant_dropped' => true], ActivityEntry::query()->orderByDesc('id')->firstOrFail()->properties?->toArray());

        $this->grant($credential);
        $writer->update($credential->fresh() ?? $credential, ['name' => 'Renamed', 'settings' => [...$credential->settings, 'client_id' => 'client-2'], 'secrets' => ['client_secret' => 'a-brand-new-secret'], 'allowed_hosts' => ['api.example.com', 'auth.example.com']]);
        $this->assertSame(0, CredentialOAuthGrant::query()->count());

        $this->grant($credential);
        $writer->delete($credential->fresh() ?? $credential);
        $this->assertSame(0, CredentialOAuthGrant::query()->count());
    }

    public function test_the_writer_checks_the_authorization_url_and_parameters_of_the_type(): void
    {
        $this->actingAs($this->admin)
            ->post($this->panelUrl('/admin/credentials'), [
                'name'     => 'Google',
                'type'     => 'oauth2_authorization_code',
                'settings' => [
                    // Off the allowed hosts, which is fine: the browser goes there, not the server.
                    'authorize_url'    => 'https://accounts.example.org/o/oauth2/auth',
                    'token_url'        => 'https://auth.example.com/token',
                    'client_id'        => 'client-1',
                    'client_auth'      => 'body',
                    'authorize_params' => 'access_type=offline',
                ],
                'secrets'       => ['client_secret' => self::SECRET],
                'allowed_hosts' => ['auth.example.com'],
            ])
            ->assertSessionHasNoErrors();

        $this->post($this->panelUrl('/admin/credentials'), [
            'name'     => 'Bad',
            'type'     => 'oauth2_authorization_code',
            'settings' => [
                'authorize_url'    => 'http://accounts.example.org/auth',
                'token_url'        => 'https://auth.example.com/token',
                'client_id'        => 'client-1',
                'client_auth'      => 'body',
                'authorize_params' => 'redirect_uri=https://evil.example',
            ],
            'secrets'       => ['client_secret' => self::SECRET],
            'allowed_hosts' => ['auth.example.com'],
        ])->assertSessionHasErrors(['settings.authorize_url', 'settings.authorize_params']);

        $this->assertSame(['Google'], Credential::query()->pluck('name')->all());
    }

    /**
     * @param  list<string>  $values
     */
    private function assertNotInSession(array $values): void
    {
        $session = serialize(session()->all());

        foreach ($values as $value) {
            $this->assertStringNotContainsString($value, $session);
        }
    }

    private function flashed(string $kind): mixed
    {
        $flash = session(SessionKey::FLASH_DATA, []);

        return is_array($flash) ? ($flash[$kind] ?? null) : null;
    }

    private function connect(Credential $credential): string
    {
        $response = $this->actingAs($this->admin)
            ->post($this->panelUrl("/admin/credentials/{$credential->id}/oauth/connect"));

        $location = $response->headers->get('X-Inertia-Location') ?? $response->headers->get('Location');
        $this->assertIsString($location);

        return $location;
    }

    private function stateOf(string $location): string
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    /**
     * @param  array<string, string>  $query
     */
    private function callbackUrl(array $query): string
    {
        return $this->panelUrl('/admin/credentials/oauth/callback?' . http_build_query($query));
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     */
    private function assertNoTokens(TestResponse $response): void
    {
        $seen = (string) $response->getContent() . json_encode(session()->all(), JSON_THROW_ON_ERROR) . json_encode($response->headers->all(), JSON_THROW_ON_ERROR);

        foreach ([self::ACCESS, self::REFRESH, self::SECRET] as $value) {
            $this->assertStringNotContainsString($value, $seen);
        }
    }
}
