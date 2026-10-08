<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture\AuthorizationFixtures;

/**
 * Fixture for {@see \Tests\Unit\Architecture\ConsoleAuthorizationTest}: its form request allows everyone.
 */
final class WithOpenFormRequest
{
    public function store(OpenRequest $request): string
    {
        return "ok";
    }
}
