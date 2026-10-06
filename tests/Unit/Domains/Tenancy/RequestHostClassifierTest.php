<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Services\RequestHostClassifier;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use App\Domains\Tenancy\ValueObjects\RequestHostKind;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestHostClassifierTest extends TestCase
{
    /**
     * @return array<string, array{string, RequestHostKind, string|null}>
     */
    public static function hosts(): array
    {
        return [
            'base domain is the platform'   => ['fapost.test', RequestHostKind::Platform, null],
            'base domain, any case'         => ['FaPost.TEST', RequestHostKind::Platform, null],
            'base domain with a port'       => ['fapost.test:8443', RequestHostKind::Platform, null],
            'base domain, trailing dot'     => ['fapost.test.', RequestHostKind::Platform, null],
            'one label is a tenant'         => ['acme.fapost.test', RequestHostKind::Tenant, 'acme'],
            'tenant, uppercase'             => ['ACME.Fapost.Test', RequestHostKind::Tenant, 'acme'],
            'tenant with a port'            => ['acme.fapost.test:8080', RequestHostKind::Tenant, 'acme'],
            'tenant with a hyphen'          => ['my-shop.fapost.test', RequestHostKind::Tenant, 'my-shop'],
            'two labels are not a tenant'   => ['a.b.fapost.test', RequestHostKind::Foreign, null],
            'uppercase two labels'          => ['A.B.FAPOST.TEST', RequestHostKind::Foreign, null],
            'foreign domain'                => ['example.com', RequestHostKind::Foreign, null],
            'foreign domain ending in base' => ['acme.notfapost.test', RequestHostKind::Foreign, null],
            'base domain as a mere suffix'  => ['notfapost.test', RequestHostKind::Foreign, null],
            'base domain under foreign'     => ['fapost.test.evil.com', RequestHostKind::Foreign, null],
            'empty label'                   => ['.fapost.test', RequestHostKind::Foreign, null],
            'underscore is not a DNS label' => ['a_b.fapost.test', RequestHostKind::Foreign, null],
            'empty host'                    => ['', RequestHostKind::Foreign, null],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function anyHost(): array
    {
        return [
            'base domain'  => ['fapost.test'],
            'tenant host'  => ['acme.fapost.test'],
            'foreign host' => ['example.com'],
            'ip address'   => ['127.0.0.1'],
        ];
    }

    #[DataProvider('hosts')]
    public function test_classifies_host_in_host_mode(string $host, RequestHostKind $kind, ?string $slug): void
    {
        $result = $this->hostMode()->classifyHost($host);

        $this->assertSame($kind, $result->kind);
        $this->assertSame($slug, $result->slug);
    }

    public function test_classifies_a_request_by_its_host_header(): void
    {
        $request = Request::create('http://ACME.fapost.test:8080/some/path');

        $result = $this->hostMode()->classify($request);

        $this->assertTrue($result->isTenant());
        $this->assertSame('acme', $result->slug);
    }

    #[DataProvider('anyHost')]
    public function test_single_mode_maps_every_host_to_the_default_tenant(string $host): void
    {
        $classifier = new RequestHostClassifier(TenancyResolutionMode::Single, 'fapost.test', 'main', new TenantSlugPolicy());

        $result = $classifier->classifyHost($host);

        $this->assertTrue($result->isTenant());
        $this->assertSame('main', $result->slug);
    }
    private function hostMode(): RequestHostClassifier
    {
        return new RequestHostClassifier(TenancyResolutionMode::Host, 'fapost.test', 'main', new TenantSlugPolicy());
    }
}
