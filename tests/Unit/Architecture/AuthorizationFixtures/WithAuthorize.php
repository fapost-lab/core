<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture\AuthorizationFixtures;

/**
 * Fixture for {@see \Tests\Unit\Architecture\ConsoleAuthorizationTest}: authorizes through the controller helper.
 */
final class WithAuthorize
{
    public function index(): string
    {
        $this->authorize("viewAny", self::class);

        return "ok";
    }

    private function authorize(string $ability, string $class): void
    {
    }
}
