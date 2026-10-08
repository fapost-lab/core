<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture\AuthorizationFixtures;

/**
 * Fixture for {@see \Tests\Unit\Architecture\ConsoleAuthorizationTest}: an action with no check.
 */
final class Unchecked
{
    public function index(): string
    {
        return "ok";
    }
}
