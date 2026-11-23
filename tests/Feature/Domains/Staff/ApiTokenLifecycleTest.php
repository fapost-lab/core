<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use App\Domains\Audit\Enums\AuditActorKind;
use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\ApiToken;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Notifications\ApiTokenExpiringNotification;
use App\Domains\Staff\Services\ApiTokenService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\FeatureTestCase;

/**
 * Issue, rotation and expiry rules of `ApiTokenService`, the operator's incident revoke, the expiry notice and the
 * permission migration (`adr-20261119-tenant-api-tokens` §2, §3, §7–§9, §12).
 */
final class ApiTokenLifecycleTest extends FeatureTestCase
{
    private User $admin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->tenant = Tenant::query()->firstOrFail();
        $this->app->make(TenantContextInterface::class)->set($this->tenant);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(RoleEnum::Admin->value);
    }

    public function test_issue_gives_the_shape_stores_only_the_hash_and_defaults_the_expiry(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');

        $issued = $this->service()->issue($this->admin, ' CRM ', [Permission::ManageSettings->value]);

        $this->assertMatchesRegularExpression(ApiToken::PATTERN, $issued->plain);
        [, $publicId, $secret] = explode('_', $issued->plain);
        $this->assertSame($issued->token->getKey(), ApiToken::rowIdOf($publicId));
        $this->assertSame(hash('sha256', $secret), $issued->token->token_hash);
        $this->assertSame(mb_substr($secret, -4), $issued->token->last_four);
        $this->assertSame('CRM', $issued->token->name);
        $this->assertSame('2027-02-18', $issued->token->expires_at->toDateString());
        $this->assertSame(0, DB::table('api_tokens')->where('token_hash', $secret)->count());

        Carbon::setTestNow();
    }

    public function test_the_expiry_is_bounded_by_the_maximum(): void
    {
        config(['api_tokens.max_ttl_days' => 30]);

        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->assertSame('2026-12-20', $this->service()->issue($this->admin, 'A', [Permission::ManageSettings->value], 30)->token->expires_at->toDateString());
        // The default never exceeds the maximum.
        $this->assertSame('2026-12-20', $this->service()->issue($this->admin, 'D', [Permission::ManageSettings->value])->token->expires_at->toDateString());
        Carbon::setTestNow();

        $this->assertRefused('expires_in_days', fn () => $this->service()->issue($this->admin, 'B', [Permission::ManageSettings->value], 31));
        $this->assertRefused('expires_in_days', fn () => $this->service()->issue($this->admin, 'C', [Permission::ManageSettings->value], 0));
    }

    public function test_no_one_grants_a_permission_a_token_may_never_hold(): void
    {
        foreach ([Permission::ManageApiTokens, Permission::ManageRoles, Permission::ManageUsers, Permission::ViewOperatorScreens] as $permission) {
            $this->assertRefused('abilities', fn () => $this->service()->issue($this->admin, 'X', [$permission->value]));
        }
    }

    public function test_a_non_admin_grants_only_tenant_wide_permissions_they_hold(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageApiTokens->value, Permission::ManageSettings->value, Permission::PublishFlow->value, Permission::StartFlowRuns->value);

        $this->assertNotNull($this->service()->issue($user, 'OK', [Permission::ManageSettings->value])->token);
        // Held, but narrowed by assistant assignment.
        $this->assertRefused('abilities', fn () => $this->service()->issue($user, 'X', [Permission::PublishFlow->value]));
        $this->assertRefused('abilities', fn () => $this->service()->issue($user, 'X', [Permission::StartFlowRuns->value]));
        // Tenant-wide, but not held.
        $this->assertRefused('abilities', fn () => $this->service()->issue($user, 'X', [Permission::ViewActivityLog->value]));
    }

    public function test_rotation_keeps_the_name_never_widens_and_shortens_the_predecessor(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $old = $this->service()->issue($this->admin, 'CRM', [Permission::ManageSettings->value, Permission::ViewSystem->value])->token;

        $this->assertRefused('abilities', fn () => $this->service()->rotate($this->admin, $old, [Permission::ManageSettings->value, Permission::ViewActivityLog->value]));

        $new = $this->service()->rotate($this->admin, $old, [Permission::ViewSystem->value])->token;

        $this->assertSame('CRM', $new->name);
        $this->assertSame([Permission::ViewSystem->value], $new->abilities);
        $old->refresh();
        $this->assertSame('2026-11-21 10:00:00', $old->expires_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($old->expiry_notified_at, 'A rotated token is never warned about.');
        $this->assertNull($old->revoked_at);

        // Rotating again never extends the predecessor.
        Carbon::setTestNow('2026-11-21 09:00:00');
        $this->service()->rotate($this->admin, $old);
        $this->assertSame('2026-11-21 10:00:00', $old->refresh()->expires_at->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_a_rotator_inherits_only_what_they_can_grant(): void
    {
        $old  = $this->service()->issue($this->admin, 'CRM', [Permission::ManageSettings->value, Permission::ViewSystem->value])->token;
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageApiTokens->value, Permission::ManageSettings->value);

        $this->assertRefused('abilities', fn () => $this->service()->rotate($user, $old));
        $this->assertSame([Permission::ManageSettings->value], $this->service()->rotate($user, $old, [Permission::ManageSettings->value])->token->abilities);
    }

    public function test_an_expired_or_revoked_token_is_not_rotated(): void
    {
        $token = $this->service()->issue($this->admin, 'CRM', [Permission::ManageSettings->value])->token;
        $this->service()->revoke($this->admin, $token);

        $this->assertRefused('token', fn () => $this->service()->rotate($this->admin, $token));

        $expired = $this->service()->issue($this->admin, 'Old', [Permission::ManageSettings->value])->token;
        $expired->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();

        $this->assertRefused('token', fn () => $this->service()->rotate($this->admin, $expired));
    }

    public function test_issue_rotate_and_revoke_are_audited_without_the_secret(): void
    {
        $this->actingAs($this->admin);

        $issued  = $this->service()->issue($this->admin, 'CRM', [Permission::ManageSettings->value]);
        $rotated = $this->service()->rotate($this->admin, $issued->token);
        $this->service()->revoke($this->admin, $rotated->token);

        $entries = ActivityEntry::query()->where('log_name', 'settings')->orderBy('created_at')->get();
        $this->assertSame(['api_token.issued', 'api_token.rotated', 'api_token.revoked'], $entries->pluck('description')->all());

        foreach ($entries as $entry) {
            $this->assertSame(AuditActorKind::Staff, $entry->actor_kind);
            $this->assertSame('CRM', $entry->subject_label);

            $stored = json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR) . json_encode($entry->properties, JSON_THROW_ON_ERROR);

            foreach ([$issued, $rotated] as $token) {
                [, , $secret] = explode('_', $token->plain);
                $this->assertStringNotContainsString($secret, $stored);
                $this->assertStringNotContainsString($token->token->token_hash, $stored);
            }
        }

        $this->assertSame($this->admin->getKey(), $rotated->token->refresh()->revoked_by);
    }

    public function test_the_operator_revokes_only_with_the_token_itself_and_is_audited_as_support(): void
    {
        $issued       = $this->service()->issue($this->admin, 'CRM', [Permission::ManageSettings->value]);
        [, $publicId] = explode('_', $issued->plain);

        // The right id with a wrong secret revokes nothing.
        $this->artisan('tenants:api-token:revoke', ['tenant' => $this->tenant->slug, '--operator' => 'Olga'])
            ->expectsQuestion('The leaked token', 'fpt_' . $publicId . '_' . str_repeat('a', 40))
            ->assertFailed();
        $this->assertNull($issued->token->refresh()->revoked_at);

        $this->artisan('tenants:api-token:revoke', ['tenant' => $this->tenant->slug])->assertFailed();

        $this->artisan('tenants:api-token:revoke', ['tenant' => $this->tenant->slug, '--operator' => 'Olga'])
            ->expectsQuestion('The leaked token', $issued->plain)
            ->assertSuccessful();

        $token = $issued->token->refresh();
        $this->assertNotNull($token->revoked_at);
        $this->assertNull($token->revoked_by);

        $entry = ActivityEntry::query()->where('description', 'api_token.revoked')->sole();
        $this->assertSame(AuditActorKind::Support, $entry->actor_kind);
        $this->assertSame('Olga', $entry->actor_label);
        $this->assertNull($entry->causer_id);
    }

    public function test_admins_are_warned_once_about_a_token_that_expires_within_two_weeks(): void
    {
        Notification::fake();

        $soon  = $this->service()->issue($this->admin, 'Soon', [Permission::ManageSettings->value], 10)->token;
        $later = $this->service()->issue($this->admin, 'Later', [Permission::ManageSettings->value], 30)->token;
        $gone  = $this->service()->issue($this->admin, 'Gone', [Permission::ManageSettings->value], 5)->token;
        $this->service()->revoke($this->admin, $gone);

        $this->artisan('api-tokens:notify-expiring')->assertSuccessful();
        $this->artisan('api-tokens:notify-expiring')->assertSuccessful();

        Notification::assertSentToTimes($this->admin, ApiTokenExpiringNotification::class, 1);
        $this->assertNotNull($soon->refresh()->expiry_notified_at);
        $this->assertNull($later->refresh()->expiry_notified_at);
        $this->assertNull($gone->refresh()->expiry_notified_at);
    }

    public function test_the_permission_migration_creates_the_rows_and_grants_only_the_admin_role(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/tenant/2026_11_20_100002_add_api_token_permissions.php');
        $migration->down();

        $this->assertSame(0, DB::table('permissions')->whereIn('name', ['manage_api_tokens', 'start_flow_runs'])->count());

        $migration->up();
        $migration->up();

        $grants = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->whereIn('permissions.name', ['manage_api_tokens', 'start_flow_runs'])
            ->orderBy('permissions.name')
            ->select(['permissions.name as permission', 'roles.name as role'])
            ->pluck('role', 'permission')
            ->all();

        $this->assertSame(['manage_api_tokens' => 'admin', 'start_flow_runs' => 'admin'], $grants);
        $this->assertSame(2, DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->whereIn('permissions.name', ['manage_api_tokens', 'start_flow_runs'])
            ->count());
        $this->assertTrue(Str::isUuid((string) DB::table('permissions')->where('name', 'start_flow_runs')->value('id')));
    }

    private function service(): ApiTokenService
    {
        return $this->app->make(ApiTokenService::class);
    }

    private function assertRefused(string $key, callable $call): void
    {
        try {
            $call();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());

            return;
        }

        $this->fail("Expected a refusal on {$key}.");
    }
}
