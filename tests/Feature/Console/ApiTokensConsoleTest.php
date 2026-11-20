<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\ApiToken;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\SupportAccessTokenStore;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Tenancy\DTO\SupportAccessRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\FeatureTestCase;

/**
 * The API tokens screen in the admin panel: the list, issuing, rotating and revoking, who may do which, what a
 * non-administrator may grant, and that the plain token reaches the browser once and is stored nowhere.
 */
final class ApiTokensConsoleTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());
        $this->seed(TenantAclSeeder::class);
    }

    public function test_the_list_shows_the_tokens_without_a_secret(): void
    {
        $admin = $this->admin();
        $token = $this->issue($admin, 'CRM', [Permission::ManageSettings->value, Permission::ViewActivityLog->value]);

        $response = $this->actingAs($admin)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Console/ApiTokens/Index')
                ->where('navigation.mode', 'admin')
                ->where('table.meta.total', 1)
                ->where('table.rows.0.id', $token->id)
                ->where('table.rows.0.name', 'CRM')
                ->where('table.rows.0.last_four', $token->last_four)
                ->where('table.rows.0.status', 'active')
                ->where('table.rows.0.issuer', ['name' => $admin->name, 'active' => true])
                ->where('table.rows.0.abilities.0.value', Permission::ManageSettings->value)
                ->where('table.rows.0.rotateUrl', "/admin/api-tokens/{$token->id}/rotate")
                ->where('lifetime', ['default' => 90, 'max' => 365])
                ->where('can', ['manage' => true])
                ->where('grantable', fn ($grantable): bool => ! collect($grantable)->pluck('value')->contains(Permission::ManageApiTokens->value)
                    && collect($grantable)->pluck('value')->contains(Permission::StartFlowRuns->value))
                ->etc());

        $this->assertStringNotContainsString($token->token_hash, $response->getContent());
    }

    public function test_the_status_follows_expiry_and_revocation_and_a_deleted_issuer_is_shown_as_none(): void
    {
        $admin   = $this->admin();
        $expired = $this->issue($admin, 'Old', [Permission::ManageSettings->value]);
        $revoked = $this->issue($admin, 'Gone', [Permission::ManageSettings->value]);

        $expired->forceFill(['expires_at' => Carbon::now()->subDay()])->save();
        $revoked->forceFill(['revoked_at' => Carbon::now()])->save();

        $orphan = $this->issue($admin, 'Orphan', [Permission::ManageSettings->value]);
        $orphan->forceFill(['created_by' => null])->save();

        $this->actingAs($admin)
            ->get($this->url('?sort=name&per_page=25'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('table.rows', fn ($rows): bool => ['revoked', 'expired', 'active'] === collect($rows)->sortBy('name')->pluck('status')->values()->all()
                    && null === collect($rows)->firstWhere('name', 'Orphan')['issuer'])
                ->etc());
    }

    public function test_a_user_without_the_permission_is_refused_and_does_not_see_the_menu_item(): void
    {
        $token = $this->issue($this->admin(), 'CRM', [Permission::ManageSettings->value]);

        $denied = User::factory()->create();
        $denied->givePermissionTo(Permission::ManageSettings->value, Permission::ManageCredentials->value);

        $this->actingAs($denied);

        $this->get($this->url())->assertForbidden();
        $this->post($this->url(), ['name' => 'X', 'abilities' => [Permission::ManageSettings->value]])->assertForbidden();
        $this->post($this->url("/{$token->id}/rotate"))->assertForbidden();
        $this->delete($this->url("/{$token->id}"))->assertForbidden();

        $this->assertSame(1, ApiToken::query()->count());
        $this->assertNull(ApiToken::query()->findOrFail($token->id)->revoked_at);

        $this->get('/admin/credentials')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('navigation.groups', fn ($groups): bool => ! $this->hasMenuItem($groups))
            ->etc());
    }

    public function test_the_menu_lists_the_screen_for_a_holder_of_the_permission(): void
    {
        $holder = User::factory()->create();
        $holder->givePermissionTo(Permission::ManageApiTokens->value);

        $this->actingAs($holder)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('navigation.groups', fn ($groups): bool => $this->hasMenuItem($groups))
                ->etc());
    }

    public function test_issuing_flashes_the_plain_token_once_and_stores_only_the_hash(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)
            ->post($this->url(), [
                'name'            => 'CRM',
                'abilities'       => [Permission::ManageSettings->value],
                'expires_in_days' => 30,
            ])
            ->assertRedirect('/admin/api-tokens');

        $token = ApiToken::query()->sole();
        $flash = $response->baseRequest->session()->get(SessionKey::FLASH_DATA, []);
        $plain = $flash['apiToken']['plain'] ?? null;

        $this->assertIsString($plain);
        $this->assertSame('CRM', $flash['apiToken']['name']);
        $this->assertSame(1, preg_match(ApiToken::PATTERN, $plain, $parts));
        $this->assertSame(ApiToken::rowIdOf($parts[1]), $token->id);
        $this->assertSame(hash('sha256', $parts[2]), $token->token_hash);
        $this->assertSame(mb_substr($parts[2], -4), $token->last_four);
        $this->assertSame([Permission::ManageSettings->value], $token->abilities);
        $this->assertSame($admin->id, $token->created_by);
        $this->assertTrue($token->expires_at->between(Carbon::now()->addDays(29), Carbon::now()->addDays(31)));
        $this->assertDatabaseMissing('api_tokens', ['token_hash' => $plain]);

        $entry = ActivityEntry::query()->where('description', 'api_token.issued')->sole();
        $dump  = json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($parts[2], $dump);
        $this->assertStringNotContainsString($token->token_hash, $dump);
        $this->assertStringContainsString($token->last_four, $dump);

        // The page the redirect lands on carries it once, as flash; the next one does not.
        $this->actingAs($admin)->get($this->url())->assertOk();
        $this->assertStringNotContainsString($parts[2], $this->actingAs($admin)->get($this->url())->assertOk()->getContent());
    }

    public function test_issuing_refuses_a_bad_request_with_errors_by_field(): void
    {
        $this->actingAs($this->admin())
            ->postJson($this->url(), ['name' => '', 'abilities' => [], 'expires_in_days' => 9999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'abilities', 'expires_in_days']);

        $this->assertSame(0, ApiToken::query()->count());
    }

    public function test_nobody_can_give_a_token_the_right_to_manage_tokens(): void
    {
        $this->actingAs($this->admin())
            ->postJson($this->url(), ['name' => 'X', 'abilities' => [Permission::ManageApiTokens->value]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('abilities');

        $this->assertSame(0, ApiToken::query()->count());
    }

    public function test_a_non_administrator_gives_only_what_they_hold_and_only_workspace_wide_permissions(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageApiTokens->value, Permission::ManageSettings->value, Permission::ManageFlowDefinitions->value);

        $this->actingAs($user)
            ->get($this->url())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('grantable', fn ($grantable): bool => [Permission::ManageSettings->value] === collect($grantable)->pluck('value')->all())
                ->etc());

        $this->post($this->url(), ['name' => 'Settings', 'abilities' => [Permission::ManageSettings->value]])
            ->assertRedirect('/admin/api-tokens');

        $this->assertSame([Permission::ManageSettings->value], ApiToken::query()->sole()->abilities);

        // Held, but narrowed by a record policy for a non-administrator.
        $this->postJson($this->url(), ['name' => 'Flows', 'abilities' => [Permission::ManageFlowDefinitions->value]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('abilities');

        // Not held at all.
        $this->postJson($this->url(), ['name' => 'Credentials', 'abilities' => [Permission::ManageCredentials->value]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('abilities');

        $this->assertSame(1, ApiToken::query()->count());
    }

    public function test_rotating_keeps_the_name_refuses_widening_and_shortens_the_predecessor(): void
    {
        $admin       = $this->admin();
        $predecessor = $this->issue($admin, 'CRM', [Permission::ManageSettings->value, Permission::ViewActivityLog->value]);

        $this->actingAs($admin)
            ->postJson($this->url("/{$predecessor->id}/rotate"), ['abilities' => [Permission::ManageSettings->value, Permission::ManageCredentials->value]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('abilities');

        $this->assertSame(1, ApiToken::query()->count());

        $response = $this->post($this->url("/{$predecessor->id}/rotate"), ['abilities' => [Permission::ManageSettings->value]])
            ->assertRedirect('/admin/api-tokens');

        $successor = ApiToken::query()->whereKeyNot($predecessor->id)->sole();

        $this->assertSame('CRM', $successor->name);
        $this->assertSame([Permission::ManageSettings->value], $successor->abilities);
        $this->assertNotSame($predecessor->token_hash, $successor->token_hash);
        $this->assertNotNull($response->baseRequest->session()->get(SessionKey::FLASH_DATA)['apiToken']['plain'] ?? null);

        $predecessor->refresh();
        $this->assertNull($predecessor->revoked_at);
        $this->assertTrue($predecessor->expires_at->lessThanOrEqualTo(Carbon::now()->addHours(24)->addMinute()));
        $this->assertSame(1, ActivityEntry::query()->where('description', 'api_token.rotated')->count());
    }

    public function test_revoking_sets_the_time_and_the_actor_and_goes_back(): void
    {
        $admin = $this->admin();
        $token = $this->issue($admin, 'CRM', [Permission::ManageSettings->value]);

        $this->actingAs($admin)
            ->from($this->url('?search=crm'))
            ->delete($this->url("/{$token->id}"))
            ->assertRedirect($this->url('?search=crm'));

        $token->refresh();

        $this->assertNotNull($token->revoked_at);
        $this->assertSame($admin->id, $token->revoked_by);
        $this->assertSame(1, ActivityEntry::query()->where('description', 'api_token.revoked')->count());
    }

    public function test_the_platform_support_user_does_nothing_here(): void
    {
        $token = $this->issue($this->admin(), 'CRM', [Permission::ManageSettings->value]);

        // The operator enters through the support entry, as in production: the support user is an administrator.
        config(['tenancy.support_access.enabled' => true]);

        $entry = $this->app->make(SupportAccessTokenStore::class)
            ->issue(new SupportAccessRequest(Tenant::query()->firstOrFail()->getId(), 'operator:7', 'Olga', 'olga@example.com'))['token'];

        $this->post(route('support.enter'), ['token' => $entry])->assertRedirect(route('console.admin.dashboard'));
        $this->assertTrue(Auth::user()?->isPlatformSupport());

        $this->get($this->url())->assertForbidden();
        $this->post($this->url(), ['name' => 'X', 'abilities' => [Permission::ManageSettings->value]])->assertForbidden();
        $this->post($this->url("/{$token->id}/rotate"))->assertForbidden();
        $this->delete($this->url("/{$token->id}"))->assertForbidden();

        $this->get('/admin/credentials')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('navigation.groups', fn ($groups): bool => ! $this->hasMenuItem($groups))
            ->etc());

        $this->assertSame(1, ApiToken::query()->count());
        $this->assertNull(ApiToken::query()->findOrFail($token->id)->revoked_at);
    }

    public function test_an_unknown_or_malformed_id_is_a_404(): void
    {
        $this->actingAs($this->admin());

        foreach (['not-a-uuid', '00000000-0000-4000-8000-0000000000ff'] as $id) {
            $this->post($this->url("/{$id}/rotate"))->assertNotFound();
            $this->delete($this->url("/{$id}"))->assertNotFound();
        }
    }

    public function test_a_visitor_is_sent_to_the_sign_in(): void
    {
        $this->get($this->url())->assertRedirect(route('console.auth.login'));
    }

    /**
     * @param  list<string>  $abilities
     */
    private function issue(User $actor, string $name, array $abilities): ApiToken
    {
        return $this->app->make(\App\Domains\Staff\Services\ApiTokenService::class)
            ->issue($actor, $name, $abilities)
            ->token;
    }

    /**
     * @param  iterable<array<string, mixed>>  $groups
     */
    private function hasMenuItem(iterable $groups): bool
    {
        return collect($groups)
            ->flatMap(static fn (array $group): array => $group['items'])
            ->contains(static fn (array $item): bool => 'console.admin.api-tokens.index' === $item['key']);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    private function url(string $suffix = ''): string
    {
        return $this->panelUrl("/admin/api-tokens{$suffix}");
    }
}
