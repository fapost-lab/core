<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Staff;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\ApiToken;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\ApiTokenService;
use App\Domains\Staff\Services\CoreOperatorAccess;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\FeatureTestCase;

/**
 * The Staff `Gate::before` for tenant API tokens (`adr-20261119-tenant-api-tokens` §7, §8): a token answers from its
 * abilities and is fail-closed, never reaching a policy, the operator screens or the administrators' bypass; the
 * abilities a token may never hold are refused even when its row lists them; and the platform support user, an
 * administrator, does nothing with tokens although its role holds the permission.
 */
final class ApiTokenGateTest extends FeatureTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());

        $this->admin = User::factory()->create();
        $this->admin->assignRole(RoleEnum::Admin->value);
    }

    public function test_a_token_holds_exactly_its_abilities_and_nothing_else_is_null(): void
    {
        $token = $this->token([Permission::ManageSettings->value]);
        $gate  = Gate::forUser($token);

        $this->assertTrue($gate->allows(Permission::ManageSettings->value));
        $this->assertFalse($gate->allows(Permission::ViewSystem->value));
        // A policy ability on a model is refused before the policy is asked, the administrators' bypass is never reached.
        $this->assertFalse($gate->allows('view', $this->admin));
        $this->assertFalse($gate->allows('viewAny', User::class));
        $this->assertFalse($gate->allows('an-ability-nobody-defined'));
        // Operator screens: refused, not left to the operator contract.
        foreach (CoreOperatorAccess::GATE_ABILITIES as $ability) {
            $this->assertFalse($gate->allows($ability), $ability);
        }
    }

    public function test_abilities_a_token_may_never_hold_are_refused_even_from_its_row(): void
    {
        $forbidden = array_values(array_filter(Permission::cases(), static fn (Permission $p): bool => $p->isForbiddenToTokens()));
        $token     = $this->token(array_map(static fn (Permission $p): string => $p->value, $forbidden));

        foreach ($forbidden as $permission) {
            $this->assertFalse(Gate::forUser($token)->allows($permission->value), $permission->value);
        }
    }

    public function test_an_expired_or_revoked_token_holds_nothing(): void
    {
        $expired = $this->token([Permission::ManageSettings->value]);
        $expired->forceFill(['expires_at' => Carbon::now()->subSecond()]);

        $revoked = $this->token([Permission::ManageSettings->value]);
        $revoked->forceFill(['revoked_at' => Carbon::now()]);

        $this->assertFalse(Gate::forUser($expired)->allows(Permission::ManageSettings->value));
        $this->assertFalse(Gate::forUser($revoked)->allows(Permission::ManageSettings->value));
    }

    public function test_the_support_user_does_nothing_with_tokens_although_its_admin_role_holds_the_permission(): void
    {
        $support = $this->app->make(PlatformSupportUserService::class)->ensure();
        $token   = $this->token([Permission::ManageSettings->value]);

        $this->assertTrue($support->isAdmin());
        $this->assertTrue($support->hasPermissionTo(Permission::ManageApiTokens->value));

        $gate = Gate::forUser($support);
        $this->assertFalse($gate->allows(Permission::ManageApiTokens->value));
        $this->assertFalse($gate->allows('view', $token));
        $this->assertFalse($gate->allows('delete', $token));
        $this->assertFalse($gate->allows('create', ApiToken::class));
        // Everything else stays as it was for an administrator.
        $this->assertTrue($gate->allows(Permission::ManageSettings->value));

        $this->expectException(AuthorizationException::class);
        $this->app->make(ApiTokenService::class)->issue($support, 'X', [Permission::ManageSettings->value]);
    }

    public function test_an_administrator_still_passes(): void
    {
        $this->assertTrue(Gate::forUser($this->admin)->allows(Permission::ManageApiTokens->value));
        $this->assertTrue(Gate::forUser($this->admin)->allows('view', $this->token([Permission::ManageSettings->value])));
    }

    /**
     * @param  list<string>  $abilities
     */
    private function token(array $abilities): ApiToken
    {
        return ApiToken::query()->create([
            'name'       => 'Test',
            'token_hash' => hash('sha256', bin2hex(random_bytes(16))),
            'last_four'  => 'abcd',
            'abilities'  => $abilities,
            'created_by' => $this->admin->getKey(),
            'expires_at' => Carbon::now()->addDays(30),
        ]);
    }
}
