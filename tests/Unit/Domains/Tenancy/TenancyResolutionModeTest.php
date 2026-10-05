<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Contracts\TenantResolverInterface;
use App\Domains\Tenancy\Exceptions\InvalidTenancyResolutionModeException;
use App\Domains\Tenancy\Exceptions\InvalidTenantSlugException;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Services\ConfigTenantResolver;
use App\Domains\Tenancy\Services\HostTenantResolver;
use App\Domains\Tenancy\Services\RequestHostClassifier;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use App\Domains\Tenancy\Support\TenantHost;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class TenancyResolutionModeTest extends TestCase
{
    public function test_defaults_to_single(): void
    {
        $this->assertSame(TenancyResolutionMode::Single, TenancyResolutionMode::fromConfig());
    }

    public function test_resolver_follows_the_configured_mode(): void
    {
        config(['tenancy.resolution' => 'single']);
        $this->assertInstanceOf(ConfigTenantResolver::class, $this->app->make(TenantResolverInterface::class));

        config(['tenancy.resolution' => 'host']);
        $this->assertInstanceOf(HostTenantResolver::class, $this->app->make(TenantResolverInterface::class));
    }

    public function test_unknown_mode_fails_when_the_mode_is_used_with_a_clear_message(): void
    {
        config(['tenancy.resolution' => 'subdomain']);

        $this->expectException(InvalidTenancyResolutionModeException::class);
        $this->expectExceptionMessage('Invalid TENANCY_RESOLUTION value [subdomain]: use one of single, host.');

        $this->app->make(RequestHostClassifier::class);
    }

    public function test_unknown_mode_fails_the_resolver_binding_too(): void
    {
        config(['tenancy.resolution' => 'subdomain']);

        $this->expectException(InvalidTenancyResolutionModeException::class);

        $this->app->make(TenantResolverInterface::class);
    }

    public function test_unknown_mode_does_not_stop_the_application_from_booting(): void
    {
        // `config:clear` and `package:discover` boot the application; a bad value must
        // leave them working so that it can be fixed. The value is set as a real process
        // variable: the .env loader is immutable, so it cannot override it even when .env
        // defines TENANCY_RESOLUTION itself (it does on CI, copied from .env.example).
        $previous = [
            'env'    => $_ENV['TENANCY_RESOLUTION'] ?? null,
            'server' => $_SERVER['TENANCY_RESOLUTION'] ?? null,
            'getenv' => getenv('TENANCY_RESOLUTION'),
        ];
        // Forget the value the loader wrote itself on boot, so the one below counts as
        // externally defined and survives the reload.
        Env::getRepository()->clear('TENANCY_RESOLUTION');
        $_ENV['TENANCY_RESOLUTION'] = $_SERVER['TENANCY_RESOLUTION'] = 'subdomain';
        putenv('TENANCY_RESOLUTION=subdomain');

        try {
            $this->refreshApplication();

            $this->assertSame('subdomain', config('tenancy.resolution'));
            $this->assertSame(0, Artisan::call('config:clear'));
        } finally {
            $this->restoreVariable('env', $previous['env']);
            $this->restoreVariable('server', $previous['server']);
            putenv(false === $previous['getenv'] ? 'TENANCY_RESOLUTION' : 'TENANCY_RESOLUTION=' . $previous['getenv']);
        }
    }

    public function test_default_tenant_slug_stays_assignable_in_single_mode(): void
    {
        config(['tenancy.resolution' => 'single', 'tenancy.default_tenant_slug' => 'app']);

        $this->app->make(TenantSlugPolicy::class)->assertAssignable('app');

        $this->assertTrue(true);
    }

    public function test_default_tenant_slug_is_reserved_in_host_mode(): void
    {
        config(['tenancy.resolution' => 'host', 'tenancy.default_tenant_slug' => 'app']);

        $policy = $this->app->make(TenantSlugPolicy::class);

        $this->assertTrue($policy->isReserved('app'));
        $this->expectException(InvalidTenantSlugException::class);

        $policy->assertAssignable('app');
    }

    public function test_single_mode_without_a_default_slug_is_a_misconfiguration(): void
    {
        config(['tenancy.resolution' => 'single', 'tenancy.default_tenant_slug' => '']);

        $this->expectException(TenantNotFoundException::class);
        $this->expectExceptionMessage('TENANT_SLUG is not configured');

        $this->app->make(RequestHostClassifier::class)->classifyHost('anything.example');
    }

    public function test_host_mode_trusts_only_the_base_domain_and_its_subdomains(): void
    {
        config(['tenancy.resolution' => 'host', 'tenancy.base_domain' => 'fapost.test']);

        $patterns = TenantHost::trustedHostPatterns();

        $this->assertCount(1, $patterns);
        $matches = fn (string $host): bool => 1 === preg_match('{' . $patterns[0] . '}i', $host);
        $this->assertTrue($matches('fapost.test'));
        $this->assertTrue($matches('acme.fapost.test'));
        $this->assertTrue($matches('A.B.Fapost.Test'));
        $this->assertFalse($matches('example.com'));
        $this->assertFalse($matches('notfapost.test'));
        $this->assertFalse($matches('fapost.test.evil.com'));
    }

    public function test_single_mode_trusts_every_host(): void
    {
        config(['tenancy.resolution' => 'single', 'tenancy.base_domain' => 'fapost.test']);

        $this->assertSame([], TenantHost::trustedHostPatterns());
    }

    private function restoreVariable(string $store, ?string $value): void
    {
        if ('env' === $store) {
            if (null === $value) {
                unset($_ENV['TENANCY_RESOLUTION']);
            } else {
                $_ENV['TENANCY_RESOLUTION'] = $value;
            }

            return;
        }

        if (null === $value) {
            unset($_SERVER['TENANCY_RESOLUTION']);
        } else {
            $_SERVER['TENANCY_RESOLUTION'] = $value;
        }
    }
}
