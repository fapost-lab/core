<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Exceptions\UnsupportedHostModeConfigurationException;
use App\Domains\Tenancy\Services\HostModeDeploymentCheck;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class HostModeDeploymentCheckTest extends TestCase
{
    public function test_a_sound_host_mode_configuration_has_nothing_to_report(): void
    {
        $check = $this->check();

        $this->assertSame([], $check->errors());
        $this->assertSame([], $check->warnings());
        $check->assertWorkable();
    }

    public function test_database_sessions_are_refused_in_host_mode(): void
    {
        $check = $this->check(['session' => ['driver' => 'database']]);

        $this->assertCount(1, $check->errors());
        $this->expectException(UnsupportedHostModeConfigurationException::class);
        $this->expectExceptionMessage('SESSION_DRIVER=database');

        $check->assertWorkable();
    }

    public function test_a_session_domain_spanning_subdomains_is_refused_in_host_mode(): void
    {
        $check = $this->check(['session' => ['domain' => '.fapost.example.com']]);

        $this->assertStringContainsString('SESSION_DOMAIN=.fapost.example.com', $check->errors()[0]);
    }

    public function test_single_mode_asks_for_nothing(): void
    {
        $check = $this->check([
            'tenancy' => ['resolution' => 'single'],
            'session' => ['driver' => 'database', 'domain' => '.fapost.example.com'],
            'webhook' => ['base_url' => 'https://elsewhere.test'],
        ]);

        $this->assertFalse($check->applies());
        $this->assertSame([], $check->errors());
        $this->assertSame([], $check->warnings());
    }

    public function test_ingress_hosts_outside_the_base_domain_are_warned_about_not_refused(): void
    {
        $check = $this->check([
            'webhook' => ['base_url' => 'https://hooks.other.test', 'ingress' => ['gateway_url' => 'https://gateway.fapost.example.com']],
        ]);

        $this->assertSame([], $check->errors());
        $this->assertCount(1, $check->warnings());
        $this->assertStringContainsString('WEBHOOK_BASE_URL points at hooks.other.test', $check->warnings()[0]);
    }

    public function test_a_lookalike_host_is_outside_the_base_domain(): void
    {
        $check = $this->check(['webhook' => ['base_url' => 'https://notfapost.example.com']]);

        $this->assertCount(1, $check->warnings());
    }
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function check(array $overrides = []): HostModeDeploymentCheck
    {
        return new HostModeDeploymentCheck(new Repository(array_replace_recursive([
            'tenancy' => ['resolution' => 'host', 'base_domain' => 'fapost.example.com'],
            'session' => ['driver' => 'redis', 'domain' => null],
            'webhook' => ['base_url' => 'https://fapost.example.com', 'ingress' => ['gateway_url' => null]],
        ], $overrides)));
    }
}
