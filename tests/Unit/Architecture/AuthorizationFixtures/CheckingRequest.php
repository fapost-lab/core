<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture\AuthorizationFixtures;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Fixture for {@see \Tests\Unit\Architecture\ConsoleAuthorizationTest}: a request that checks an ability.
 */
final class CheckingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can("manage") ?? false;
    }
}
