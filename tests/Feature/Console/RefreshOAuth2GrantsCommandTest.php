<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Credentials\Models\CredentialOAuthGrant;
use App\Domains\Credentials\OAuth2\OAuth2GrantStatus;
use App\Domains\Flow\Call\Egress\EgressGuardMiddleware;
use App\Domains\Flow\Call\Egress\GuardedHttpClient;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Domains\Credentials\CredentialsTestCase;
use Tests\Support\FakeTenantAccessMode;

/**
 * `credentials:refresh-oauth2-grants` keeps idle grants and refresh tokens about to end alive, in every active tenant
 * that is not stopped, one failure at a time.
 */
final class RefreshOAuth2GrantsCommandTest extends CredentialsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([GuardedHttpClient::class, EgressGuardMiddleware::class] as $class) {
            $this->app->forgetInstance($class);
        }
    }

    public function test_idle_grants_and_refresh_tokens_about_to_end_are_refreshed_and_the_rest_is_left_alone(): void
    {
        Http::fake(['auth.example.com/*' => Http::response(['access_token' => 'access-token-2', 'refresh_token' => 'refresh-token-2', 'expires_in' => 3600])]);

        $idle     = $this->grant($this->oauthCredential(), ['refreshed_at' => now()->subDays(8)]);
        $ending   = $this->grant($this->oauthCredential(), ['refresh_expires_at' => now()->addDays(2)]);
        $fresh    = $this->grant($this->oauthCredential(), ['refresh_expires_at' => now()->addDays(30)]);
        $noReturn = $this->grant($this->oauthCredential(), ['refreshed_at' => now()->subDays(8), 'refresh_token' => null]);
        $refused  = $this->grant($this->oauthCredential(), ['refreshed_at' => now()->subDays(8), 'status' => OAuth2GrantStatus::ReconnectNeeded]);

        $this->artisan('credentials:refresh-oauth2-grants')->assertExitCode(0);

        Http::assertSentCount(2);
        Http::assertSent(static fn (Request $request): bool => 'refresh_token' === $request['grant_type']);

        foreach ([$idle, $ending] as $grant) {
            $this->assertSame('refresh-token-2', $this->reload($grant)->refresh_token);
        }

        foreach ([$fresh, $noReturn, $refused] as $grant) {
            $this->assertSame('access-token-1', $this->reload($grant)->access_token);
        }
    }

    public function test_a_failing_grant_is_reported_and_the_others_are_still_refreshed(): void
    {
        Http::fake([
            'auth.example.com/*'  => Http::response(['error' => 'server_error'], 500),
            'auth2.example.com/*' => Http::response(['access_token' => 'access-token-2', 'expires_in' => 3600]),
        ]);

        $failing = $this->grant($this->oauthCredential(), ['refreshed_at' => now()->subDays(9)]);
        $working = $this->grant($this->oauthCredential([
            'allowed_hosts' => ['auth2.example.com'],
            'settings'      => [...$this->oauthCredential()->settings, 'token_url' => 'https://auth2.example.com/token'],
        ]), ['refreshed_at' => now()->subDays(8)]);

        $this->artisan('credentials:refresh-oauth2-grants')
            ->expectsOutputToContain("✘ main: {$failing->credential_id}")
            ->assertExitCode(0);

        $this->assertSame('access-token-1', $this->reload($failing)->access_token);
        $this->assertSame(OAuth2GrantStatus::Connected, $this->reload($failing)->status);
        $this->assertSame('access-token-2', $this->reload($working)->access_token);
    }

    public function test_a_grant_the_provider_refuses_needs_a_new_consent_and_is_not_an_audit_entry(): void
    {
        Http::fake(['auth.example.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'refresh-token-1 revoked'], 400)]);
        $grant = $this->grant($this->oauthCredential(['name' => 'CRM']), ['refreshed_at' => now()->subDays(8)]);

        $this->artisan('credentials:refresh-oauth2-grants')->assertExitCode(0);

        $this->assertSame(OAuth2GrantStatus::ReconnectNeeded, $this->reload($grant)->status);
        $this->assertSame('invalid_grant', $this->reload($grant)->failure_code);
        // A job-driven status change is not audited (adr-20261111-audit-log-explicit-recording).
        $this->assertSame(0, ActivityEntry::query()->count());
    }

    public function test_a_stopped_tenant_is_skipped(): void
    {
        Http::fake();
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped());
        $this->grant($this->oauthCredential(), ['refreshed_at' => now()->subDays(8)]);

        $this->artisan('credentials:refresh-oauth2-grants')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_it_is_scheduled_daily_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'credentials:refresh-oauth2-grants'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('30 2 * * *', $event->expression);
    }

    private function reload(CredentialOAuthGrant $grant): CredentialOAuthGrant
    {
        return CredentialOAuthGrant::query()->findOrFail($grant->credential_id);
    }
}
