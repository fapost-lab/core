<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture\AuthorizationFixtures;

/**
 * Fixture for {@see \Tests\Unit\Architecture\ConsoleAuthorizationTest}: authorizes in its form request.
 */
final class WithFormRequest
{
    public function store(CheckingRequest $request): string
    {
        return "ok";
    }
}
