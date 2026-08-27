<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Exceptions\InvalidTenantSlugException;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TenantSlugPolicyTest extends TestCase
{
    #[DataProvider('wellFormedSlugs')]
    public function test_accepts_valid_dns_labels(string $slug): void
    {
        $this->policy()->assertAssignable($slug);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function wellFormedSlugs(): array
    {
        return [
            'simple'          => ['acme'],
            'with hyphen'     => ['acme-corp'],
            'digits'          => ['acme2024'],
            'leading digit'   => ['2acme'],
            'all digits'      => ['12345'],
            'single char'     => ['a'],
            'max length'      => [str_repeat('a', 63)],
        ];
    }

    #[DataProvider('malformedSlugs')]
    public function test_rejects_invalid_dns_labels(string $slug): void
    {
        $this->expectException(InvalidTenantSlugException::class);

        $this->policy()->assertAssignable($slug);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedSlugs(): array
    {
        return [
            'empty'            => [''],
            'uppercase'        => ['Acme'],
            'space'            => ['acme corp'],
            'underscore'       => ['acme_corp'],
            'leading hyphen'   => ['-acme'],
            'trailing hyphen'  => ['acme-'],
            'dot'              => ['acme.corp'],
            'too long'         => [str_repeat('a', 64)],
            'slash'            => ['acme/corp'],
            'unicode'          => ['акме'],
            'null byte'        => ["acme\0"],
        ];
    }

    /**
     * Underscores matter beyond DNS: the schema name is derived via Str::slug with
     * an underscore separator, so "acme-corp" and "acme_corp" would both produce
     * tenant_acme_corp and collide on a name neither caller asked for.
     */
    public function test_rejecting_underscores_keeps_derived_schema_names_unique(): void
    {
        $policy = $this->policy();

        $this->assertTrue($policy->isWellFormed('acme-corp'));
        $this->assertFalse($policy->isWellFormed('acme_corp'));
    }

    public function test_rejects_reserved_slugs(): void
    {
        $this->expectException(InvalidTenantSlugException::class);

        $this->policy()->assertAssignable('webhook');
    }

    /**
     * The stored slug keeps the caller's spelling, so the reservation check must
     * not be defeated by case even though well-formed slugs are lower case.
     */
    public function test_reservation_check_is_case_insensitive(): void
    {
        $this->assertTrue($this->policy()->isReserved('WEBHOOK'));
    }

    /**
     * Punycode-prefixed labels render as non-ASCII names in browsers, which makes
     * them a ready-made lookalike for a platform host.
     */
    public function test_rejects_the_punycode_prefix(): void
    {
        $this->expectException(InvalidTenantSlugException::class);

        $this->policy()->assertAssignable('xn--80ak6aa92e');
    }

    public function test_gateway_hostname_is_reserved_from_configuration(): void
    {
        config()->set('tenancy.base_domain', 'example.com');
        config()->set('webhook.base_url', 'https://app.example.com');
        config()->set('webhook.ingress.gateway_url', 'https://webhook.example.com');
        config()->set('tenancy.default_tenant_slug', 'main');

        $reserved = app(TenantSlugPolicy::class)->reservedSlugs();

        $this->assertContains('webhook', $reserved);
        $this->assertContains('app', $reserved, 'The Laravel ingress hostname must be reserved too.');
    }

    /**
     * A gateway on an unrelated domain claims no subdomain of ours, so nothing
     * should be reserved from it.
     */
    public function test_gateway_on_a_foreign_domain_reserves_nothing_extra(): void
    {
        config()->set('tenancy.base_domain', 'example.com');
        config()->set('webhook.base_url', 'https://app.example.com');
        // Deliberately a label absent from the static list, so the assertion can
        // only pass if nothing was derived from the foreign host.
        config()->set('webhook.ingress.gateway_url', 'https://acme-hooks.another-host.net');

        $this->assertNotContains('acme-hooks', app(TenantSlugPolicy::class)->reservedSlugs());
    }

    /**
     * Reserving the configured default would make a stock installation unable to
     * provision its own tenant.
     */
    public function test_default_tenant_slug_stays_assignable(): void
    {
        config()->set('tenancy.default_tenant_slug', 'app');

        $policy = app(TenantSlugPolicy::class);

        $this->assertNotContains('app', $policy->reservedSlugs());

        $policy->assertAssignable('app');

        $this->addToAssertionCount(1);
    }

    public function test_configured_reservations_are_applied(): void
    {
        config()->set('tenancy.default_tenant_slug', 'main');

        $reserved = app(TenantSlugPolicy::class)->reservedSlugs();

        foreach (['www', 'admin', 'mail', 'autodiscover', '_acme-challenge'] as $slug) {
            $this->assertContains($slug, $reserved);
        }
    }

    private function policy(): TenantSlugPolicy
    {
        return new TenantSlugPolicy(['webhook', 'www', 'api']);
    }
}
