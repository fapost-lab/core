<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Providers\TelescopeServiceProvider;
use Laravel\Telescope\Telescope;
use Tests\TestCase;

/**
 * The support access token must not be stored by Telescope, which records request bodies in local.
 */
final class SupportAccessTelescopeTest extends TestCase
{
    public function test_the_token_parameter_is_hidden_and_the_route_is_ignored(): void
    {
        // Registered only in local, where Telescope runs; register it by hand to read what it configures.
        (new TelescopeServiceProvider($this->app))->register();

        $this->assertContains('token', Telescope::$hiddenRequestParameters);
        $this->assertContains('support/enter', config('telescope.ignore_paths'));
    }
}
