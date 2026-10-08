<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture\AuthorizationFixtures;

use Illuminate\Support\Facades\Gate;

/**
 * Fixture for {@see \Tests\Unit\Architecture\ConsoleAuthorizationTest}: authorizes through the Gate.
 */
final class WithGate
{
    public function index(): string
    {
        Gate::authorize("viewAny", self::class);

        return "ok";
    }
}
