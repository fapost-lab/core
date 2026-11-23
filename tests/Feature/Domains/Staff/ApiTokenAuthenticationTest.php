<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use App\Domains\Audit\Contracts\AuditActorResolverInterface;
use App\Domains\Audit\Enums\AuditActorKind;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\ApiTokenService;
use App\Domains\Staff\Services\HashedApiTokenAuthenticator;
use App\Domains\Staff\Support\ApiTokenGuard;
use App\Domains\Staff\Support\IssuedApiToken;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeTenantAccessMode;

/**
 * The `api-token` stack on a probe route (no real endpoint uses it yet): a token authenticates, every failure is the
 * same bare 401, the shape is checked before the database, failures and requests are rate limited, no session or
 * cookie is started, a stopped tenant refuses writes, and actions through a token are audited as the token.
 */
final class ApiTokenAuthenticationTest extends FeatureTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());

        $this->admin = User::factory()->create();
        $this->admin->assignRole(RoleEnum::Admin->value);

        Route::middleware(ApiTokenGuard::MIDDLEWARE_GROUP)->prefix('api/v1')->group(function (): void {
            $probe = static fn () => response()->json([
                'token'    => Auth::id(),
                'settings' => Gate::allows(Permission::ManageSettings->value),
                'actor'    => app(AuditActorResolverInterface::class)->current()->kind->value,
                'label'    => app(AuditActorResolverInterface::class)->current()->label,
            ]);

            Route::get('probe', $probe);
            Route::post('probe', $probe);
        });
    }

    public function test_a_valid_token_authenticates_and_is_the_audit_actor(): void
    {
        $issued = $this->issue();

        $this->call('GET', '/api/v1/probe', [], [], [], $this->bearer($issued->plain))
            ->assertOk()
            ->assertJson([
                'token'    => $issued->token->getKey(),
                'settings' => true,
                'actor'    => AuditActorKind::ApiToken->value,
                'label'    => 'API · CRM',
            ])
            ->assertHeaderMissing('Set-Cookie');
    }

    public function test_every_failure_is_the_same_bare_401_whatever_the_client_accepts(): void
    {
        $issued  = $this->issue();
        $revoked = $this->issue('Revoked');
        $expired = $this->issue('Expired');
        $revoked->token->forceFill(['revoked_at' => Carbon::now()])->save();
        $expired->token->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();
        [, $id] = explode('_', $issued->plain);

        $values = [
            null,
            'not-a-token',
            'fpt_' . $id . '_' . str_repeat('a', 40),
            'fpt_01ARZ3NDEKTSV4RRFFQ69G5FAV_' . str_repeat('b', 40),
            $revoked->plain,
            $expired->plain,
            mb_strtolower($issued->plain),
        ];

        foreach ($values as $value) {
            $this->forgetGuards();

            $response = $this->call('GET', '/api/v1/probe', [], [], [], null === $value ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $value]);

            $response->assertStatus(401);
            $this->assertSame(['error' => 'unauthenticated'], $response->json(), (string) $value);
            $response->assertHeaderMissing('Set-Cookie');
        }
    }

    public function test_a_token_in_the_query_string_is_not_read(): void
    {
        $issued = $this->issue();

        $this->get('/api/v1/probe?token=' . urlencode($issued->plain))->assertStatus(401);
    }

    public function test_a_malformed_token_never_reaches_the_database(): void
    {
        $queries = 0;
        DB::listen(static function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'api_tokens')) {
                ++$queries;
            }
        });

        foreach (['fpt_not-a-ulid_' . str_repeat('a', 40), 'fpt_01ARZ3NDEKTSV4RRFFQ69G5FAV_short', 'Bearer-less'] as $value) {
            $this->forgetGuards();
            $this->call('GET', '/api/v1/probe', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $value])->assertStatus(401);
        }

        $this->assertSame(0, $queries);
    }

    public function test_a_revoked_token_stops_working_on_the_next_request(): void
    {
        $issued = $this->issue();

        $this->call('GET', '/api/v1/probe', [], [], [], $this->bearer($issued->plain))->assertOk();

        $this->app->make(ApiTokenService::class)->revoke($this->admin, $issued->token);
        $this->forgetGuards();

        $this->call('GET', '/api/v1/probe', [], [], [], $this->bearer($issued->plain))->assertStatus(401);
    }

    public function test_a_client_that_keeps_failing_is_refused_before_any_lookup(): void
    {
        $issued = $this->issue();

        for ($i = 0; $i < HashedApiTokenAuthenticator::MAX_FAILURES; ++$i) {
            $this->forgetGuards();
            $this->call('GET', '/api/v1/probe', [], [], [], $this->bearer('fpt_01ARZ3NDEKTSV4RRFFQ69G5FAV_' . str_repeat('c', 40)))->assertStatus(401);
        }

        $this->forgetGuards();
        $this->call('GET', '/api/v1/probe', [], [], [], $this->bearer($issued->plain))->assertStatus(429);
    }

    public function test_a_token_is_limited_per_minute(): void
    {
        config(['api_tokens.per_minute' => 2]);
        $issued = $this->issue();

        foreach ([200, 200, 429] as $status) {
            $this->forgetGuards();
            $this->call('GET', '/api/v1/probe', [], [], [], $this->bearer($issued->plain))->assertStatus($status);
        }
    }

    public function test_last_used_is_written_at_most_once_a_minute(): void
    {
        $issued = $this->issue();

        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->call('GET', '/api/v1/probe', [], [], [], $this->bearer($issued->plain))->assertOk();
        $this->assertSame('2026-11-20 10:00:00', $issued->token->fresh()?->last_used_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2026-11-20 10:00:30');
        $this->forgetGuards();
        $this->call('GET', '/api/v1/probe', [], [], [], $this->bearer($issued->plain))->assertOk();
        $this->assertSame('2026-11-20 10:00:00', $issued->token->fresh()?->last_used_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_a_stopped_tenant_refuses_writes_through_a_token_and_still_answers_reads(): void
    {
        $issued = $this->issue();
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped());
        $this->app->forgetScopedInstances();

        $this->call('GET', '/api/v1/probe', [], [], [], $this->bearer($issued->plain))->assertOk();

        $this->forgetGuards();
        $this->call('POST', '/api/v1/probe', [], [], [], $this->bearer($issued->plain))
            ->assertStatus(423)
            ->assertJsonPath('error', 'tenant_stopped');
    }

    private function issue(string $name = 'CRM'): IssuedApiToken
    {
        return $this->app->make(ApiTokenService::class)->issue($this->admin, $name, [Permission::ManageSettings->value]);
    }

    /**
     * @return array<string, string>
     */
    private function bearer(string $plain): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'HTTP_ACCEPT' => 'text/html'];
    }

    /**
     * The request guard keeps the token it found for the life of the application; a real request starts a new one.
     */
    private function forgetGuards(): void
    {
        Auth::forgetGuards();
    }
}
