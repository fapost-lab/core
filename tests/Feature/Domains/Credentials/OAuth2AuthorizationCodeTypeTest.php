<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Credentials;

use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Credentials\Models\Credential;
use App\Domains\Credentials\Models\CredentialOAuthGrant;
use App\Domains\Credentials\OAuth2\OAuth2GrantLock;
use App\Domains\Credentials\OAuth2\OAuth2GrantRefresher;
use App\Domains\Credentials\OAuth2\OAuth2GrantStatus;
use App\Domains\Credentials\Types\OAuth2AuthorizationCodeType;
use App\Domains\Flow\Call\Egress\EgressGuardMiddleware;
use App\Domains\Flow\Call\Egress\GuardedHttpClient;
use Fapost\Foundation\Credentials\Contracts\CredentialStoreInterface;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Applying an `oauth2_authorization_code` credential: the stored access token as a bearer, a refresh under the lock
 * when it ran out, and what a refusal of the provider does to the grant.
 */
final class OAuth2AuthorizationCodeTypeTest extends CredentialsTestCase
{
    private const string TOKEN_URL = 'https://auth.example.com/oauth/token';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([GuardedHttpClient::class, EgressGuardMiddleware::class] as $class) {
            $this->app->forgetInstance($class);
        }
    }

    public function test_a_valid_access_token_is_sent_as_a_bearer_without_a_request(): void
    {
        Http::fake();
        $credential = $this->oauthCredential();
        $this->grant($credential);

        $authorization = $this->type()->authorize($this->secretOf($credential));

        $this->assertSame(['Authorization' => 'Bearer access-token-1'], $authorization->headers());
        $this->assertSame(
            [self::SECRET, 'access-token-1', 'refresh-token-1', base64_encode('client-1:' . self::SECRET)],
            $authorization->secrets(),
        );
        Http::assertNothingSent();
    }

    public function test_a_credential_without_a_grant_or_with_a_refused_one_is_unavailable_without_a_request(): void
    {
        Http::fake();
        $notConnected = $this->oauthCredential();
        $refused      = $this->oauthCredential();
        $this->grant($refused, ['status' => OAuth2GrantStatus::ReconnectNeeded, 'access_expires_at' => now()->subHour()]);

        foreach ([$notConnected, $refused] as $credential) {
            try {
                $this->type()->authorize($this->secretOf($credential));
                $this->fail('A credential without a usable grant must be unavailable.');
            } catch (CredentialUnavailableException) {
            }
        }

        Http::assertNothingSent();
    }

    public function test_an_expired_access_token_is_refreshed_and_a_rotated_refresh_token_is_stored(): void
    {
        Http::fake([self::TOKEN_URL => Http::response([
            'access_token'  => 'access-token-2',
            'refresh_token' => 'refresh-token-2',
            'expires_in'    => 3600,
        ])]);
        $credential = $this->oauthCredential();
        $this->grant($credential, ['access_expires_at' => now()->addSeconds(30)]);

        $authorization = $this->type()->authorize($this->secretOf($credential));

        $this->assertSame(['Authorization' => 'Bearer access-token-2'], $authorization->headers());
        Http::assertSentCount(1);
        Http::assertSent(static fn (Request $request): bool => self::TOKEN_URL === $request->url()
            && 'refresh_token' === $request['grant_type']
            && 'refresh-token-1' === $request['refresh_token']
            && ['Basic ' . base64_encode('client-1:' . self::SECRET)] === $request->header('Authorization'));

        $grant = $this->storedGrant($credential);
        $this->assertSame('access-token-2', $grant->access_token);
        $this->assertSame('refresh-token-2', $grant->refresh_token);
        $this->assertTrue($grant->access_expires_at?->isAfter(now()->addMinutes(59)));
        $this->assertStringNotContainsString('refresh-token-2', (string) $grant->getRawOriginal('refresh_token'));
    }

    public function test_a_provider_that_does_not_rotate_leaves_the_refresh_token_in_place(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'access-token-2', 'expires_in' => 3600])]);
        $credential = $this->oauthCredential();
        $this->grant($credential, ['access_expires_at' => now()->subMinute()]);

        $this->type()->authorize($this->secretOf($credential));

        $this->assertSame('refresh-token-1', $this->storedGrant($credential)->refresh_token);
    }

    public function test_invalid_grant_asks_for_a_new_consent_with_no_audit_entry_and_no_more_requests(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token refresh-token-1 revoked'], 400)]);
        $credential = $this->oauthCredential(['name' => 'CRM']);
        $this->grant($credential, ['access_expires_at' => now()->subMinute()]);

        try {
            $this->type()->authorize($this->secretOf($credential));
            $this->fail('A refused refresh must be unavailable.');
        } catch (CredentialUnavailableException $exception) {
            $this->assertStringNotContainsString('refresh-token-1', $exception->getMessage());
            $this->assertStringNotContainsString('revoked', $exception->getMessage());
        }

        $grant = $this->storedGrant($credential);
        $this->assertSame(OAuth2GrantStatus::ReconnectNeeded, $grant->status);
        $this->assertSame('invalid_grant', $grant->failure_code);

        // A status change made by a call is not audited (adr-20261111-audit-log-explicit-recording).
        $this->assertSame(0, ActivityEntry::query()->count());

        try {
            $this->type()->authorize($this->secretOf($credential));
        } catch (CredentialUnavailableException) {
        }

        Http::assertSentCount(1);
    }

    public function test_a_server_error_changes_nothing_and_is_unavailable(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'server_error'], 503)]);
        $credential = $this->oauthCredential();
        $this->grant($credential, ['access_expires_at' => now()->subMinute()]);

        try {
            $this->type()->authorize($this->secretOf($credential));
            $this->fail('A failed refresh must be unavailable.');
        } catch (CredentialUnavailableException $exception) {
            $this->assertStringContainsString('status 503', $exception->getMessage());
        }

        $grant = $this->storedGrant($credential);
        $this->assertSame(OAuth2GrantStatus::Connected, $grant->status);
        $this->assertSame('refresh-token-1', $grant->refresh_token);
        $this->assertSame(0, ActivityEntry::query()->count());
    }

    public function test_an_expired_access_token_without_a_refresh_token_asks_for_a_new_consent(): void
    {
        Http::fake();
        $credential = $this->oauthCredential();
        $this->grant($credential, ['refresh_token' => null, 'access_expires_at' => now()->subMinute()]);

        $this->expectException(CredentialUnavailableException::class);

        try {
            $this->type()->authorize($this->secretOf($credential));
        } finally {
            $this->assertSame(OAuth2GrantStatus::ReconnectNeeded, $this->storedGrant($credential)->status);
            $this->assertSame(OAuth2GrantRefresher::ACCESS_EXPIRED, $this->storedGrant($credential)->failure_code);
            Http::assertNothingSent();
        }
    }

    public function test_a_token_without_an_expiry_never_needs_a_refresh(): void
    {
        Http::fake();
        $credential = $this->oauthCredential();
        $this->grant($credential, ['refresh_token' => null, 'access_expires_at' => null]);

        $this->assertSame(['Authorization' => 'Bearer access-token-1'], $this->type()->authorize($this->secretOf($credential))->headers());
        Http::assertNothingSent();
    }

    public function test_a_refresh_held_by_another_worker_is_unavailable_and_sends_nothing(): void
    {
        Http::fake();
        $this->app->instance(OAuth2GrantLock::class, new OAuth2GrantLock(Cache::store(), 0));
        $credential = $this->oauthCredential();
        $this->grant($credential, ['access_expires_at' => now()->subMinute()]);

        $held = Cache::lock("credentials:oauth2-refresh:{$this->tenantId}:{$credential->id}", 30);
        $this->assertTrue($held->get());

        try {
            $this->type()->authorize($this->secretOf($credential));
            $this->fail('A refresh without the lock must not happen.');
        } catch (CredentialUnavailableException $exception) {
            $this->assertStringContainsString('refreshed elsewhere', $exception->getMessage());
        } finally {
            $held->release();
        }

        Http::assertNothingSent();
    }

    public function test_the_grant_is_read_again_under_the_lock_and_one_refreshed_meanwhile_is_not_refreshed_twice(): void
    {
        Http::fake();
        $credential = $this->oauthCredential();
        // What another worker left while this one waited for the lock: a fresh access token.
        $this->grant($credential, ['access_token' => 'access-token-2', 'access_expires_at' => now()->addHour()]);

        $grant = $this->app->make(OAuth2GrantRefresher::class)->refreshIfDue(
            $this->secretOf($credential),
            static fn (CredentialOAuthGrant $current): bool => !$current->accessValidFor(OAuth2AuthorizationCodeType::EXPIRY_MARGIN_SECONDS),
        );

        $this->assertSame('access-token-2', $grant->access_token);
        Http::assertNothingSent();
    }

    public function test_a_token_url_off_the_allowed_hosts_is_unavailable(): void
    {
        Http::fake();
        $credential = $this->oauthCredential(['allowed_hosts' => ['api.example.com']]);
        $this->grant($credential);

        $this->expectException(CredentialUnavailableException::class);

        $this->type()->authorize($this->secretOf($credential));
    }

    public function test_a_401_from_the_target_marks_the_access_token_expired(): void
    {
        $credential = $this->oauthCredential();
        $this->grant($credential);

        $this->type()->tokenRejected($this->secretOf($credential));

        $this->assertFalse($this->storedGrant($credential)->accessValidFor(0));
    }

    public function test_the_authorization_url_must_be_https_and_the_extra_parameters_may_not_set_ours(): void
    {
        $type = $this->type();

        $this->assertNull($type->settingError('authorize_url', 'https://accounts.example.org/o/oauth2/auth?x=1'));
        $this->assertNotNull($type->settingError('authorize_url', 'http://accounts.example.org/auth'));
        $this->assertNotNull($type->settingError('authorize_url', 'https://user:pass@accounts.example.org/auth'));
        $this->assertNull($type->settingError('authorize_params', 'access_type=offline&prompt=consent'));
        $this->assertNotNull($type->settingError('authorize_params', 'prompt=consent&redirect_uri=https://evil.example'));
        $this->assertNotNull($type->settingError('authorize_params', 'STATE=x'));
        $this->assertNotNull($type->settingError('authorize_params', 'a[]=1'));
        $this->assertSame(['access_type' => 'offline', 'prompt' => 'consent'], OAuth2AuthorizationCodeType::extraParams('?access_type=offline&prompt=consent'));
    }

    private function type(): OAuth2AuthorizationCodeType
    {
        return $this->app->make(OAuth2AuthorizationCodeType::class);
    }

    private function secretOf(Credential $credential): CredentialSecret
    {
        return $this->app->make(CredentialStoreInterface::class)->secret($credential->id);
    }

    private function storedGrant(Credential $credential): CredentialOAuthGrant
    {
        return CredentialOAuthGrant::query()->findOrFail($credential->id);
    }
}
