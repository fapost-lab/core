<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Support\TenantHost;
use Tests\TestCase;

final class TenantHostUrlForTest extends TestCase
{
    public function test_host_mode_names_the_tenant_host_with_the_scheme_of_app_url(): void
    {
        config([
            'tenancy.resolution'  => 'host',
            'tenancy.base_domain' => 'example.test',
            'app.url'             => 'http://localhost',
        ]);

        $this->assertSame(
            'http://acme.example.test/activate?token=abc',
            TenantHost::urlFor(new Tenant(['slug' => 'acme']), '/activate?token=abc'),
        );
    }

    public function test_host_mode_defaults_to_https_without_a_scheme_in_app_url(): void
    {
        config([
            'tenancy.resolution'  => 'host',
            'tenancy.base_domain' => 'example.test',
            'app.url'             => '',
        ]);

        $this->assertSame(
            'https://acme.example.test/x',
            TenantHost::urlFor(new Tenant(['slug' => 'acme']), '/x'),
        );

        config(['app.url' => 'localhost']);

        $this->assertSame(
            'https://acme.example.test/x',
            TenantHost::urlFor(new Tenant(['slug' => 'acme']), '/x'),
        );
    }

    public function test_host_mode_uses_https_when_app_url_does(): void
    {
        config([
            'tenancy.resolution'  => 'host',
            'tenancy.base_domain' => 'example.test',
            'app.url'             => 'https://example.test',
        ]);

        $this->assertSame(
            'https://acme.example.test/x',
            TenantHost::urlFor(new Tenant(['slug' => 'acme']), '/x'),
        );
    }

    public function test_single_mode_is_plain_url(): void
    {
        config([
            'tenancy.resolution'  => 'single',
            'tenancy.base_domain' => 'example.test',
            'app.url'             => 'http://localhost',
        ]);

        $this->assertSame(
            url('/activate?token=abc'),
            TenantHost::urlFor(new Tenant(['slug' => 'acme']), '/activate?token=abc'),
        );
    }

    public function test_host_mode_keeps_the_port_of_app_url(): void
    {
        config([
            'tenancy.resolution'  => 'host',
            'tenancy.base_domain' => 'localhost',
            'app.url'             => 'http://localhost:8000',
        ]);

        $this->assertSame(
            'http://main.localhost:8000/activate?token=abc',
            TenantHost::urlFor(new Tenant(['slug' => 'main']), '/activate?token=abc'),
        );
    }

    public function test_host_mode_normalises_the_leading_slash_of_the_path(): void
    {
        config([
            'tenancy.resolution'  => 'host',
            'tenancy.base_domain' => 'example.test',
            'app.url'             => 'http://localhost',
        ]);

        $tenant = new Tenant(['slug' => 'acme']);

        $this->assertSame('http://acme.example.test/x?y=1', TenantHost::urlFor($tenant, 'x?y=1'));
        $this->assertSame('http://acme.example.test/x', TenantHost::urlFor($tenant, '//x'));
    }
}
