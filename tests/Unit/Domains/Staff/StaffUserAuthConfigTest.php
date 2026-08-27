<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Staff;

use App\Domains\Staff\Models\User;
use Tests\TestCase;

final class StaffUserAuthConfigTest extends TestCase
{
    public function test_auth_user_provider_uses_staff_user_model(): void
    {
        $this->assertSame(User::class, config('auth.providers.users.model'));
    }
}
