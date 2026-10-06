<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class StaffGateBeforeTest extends TestCase
{
    public function test_a_user_of_another_guard_reaches_its_own_ability_instead_of_a_type_error(): void
    {
        Gate::define('operator-only', static fn (AuthUser $user): bool => 'operator' === $user->getAuthIdentifier());

        $this->assertTrue(Gate::forUser($this->otherGuardUser('operator'))->allows('operator-only'));
        $this->assertFalse(Gate::forUser($this->otherGuardUser('someone-else'))->allows('operator-only'));
    }

    /**
     * An authenticatable of another guard — like an operator package's own account model.
     */
    private function otherGuardUser(string $id): AuthUser
    {
        $user = new class () extends AuthUser {
            protected $keyType = 'string';
        };
        $user->forceFill(['id' => $id]);

        return $user;
    }
}
