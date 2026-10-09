<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Exceptions\InvalidTenantSlugException;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\ValueObjects\SlugRejection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TenantSlugPolicyTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function wellFormedSlugs(): array
    {
        return [
            'simple'        => ['acme'],
            'with hyphen'   => ['acme-corp'],
            'digits'        => ['acme2024'],
            'leading digit' => ['2acme'],
            'all digits'    => ['12345'],
            'single char'   => ['a'],
            'max length'    => [str_repeat('a', 56)],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedSlugs(): array
    {
        return [
            'empty'           => [''],
            'uppercase'       => ['Acme'],
            'space'           => ['acme corp'],
            'underscore'      => ['acme_corp'],
            'leading hyphen'  => ['-acme'],
            'trailing hyphen' => ['acme-'],
            'dot'             => ['acme.corp'],
            'too long'        => [str_repeat('a', 64)],
            'slash'           => ['acme/corp'],
            'unicode'         => ['акме'],
            'null byte'       => ["acme\0"],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function tooLongSlugs(): array
    {
        return [
            '57 characters' => [str_repeat('a', 57)],
            '63 characters' => [str_repeat('a', 63)],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: SlugRejection}>
     */
    public static function rejections(): array
    {
        return [
            'uppercase'     => ['Acme', SlugRejection::Malformed],
            'trailing dash' => ['acme-', SlugRejection::Malformed],
            'too long'      => [str_repeat('a', 57), SlugRejection::TooLong],
            'punycode'      => ['xn--acme', SlugRejection::PunycodePrefix],
            'double hyphen' => ['ac--me', SlugRejection::ConsecutiveHyphens],
            'platform name' => ['webhook', SlugRejection::Reserved],
        ];
    }

    #[DataProvider('wellFormedSlugs')]
    public function test_accepts_valid_dns_labels(string $slug): void
    {
        $this->policy()->assertAssignable($slug);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('malformedSlugs')]
    public function test_rejects_invalid_dns_labels(string $slug): void
    {
        $this->expectException(InvalidTenantSlugException::class);

        $this->policy()->assertAssignable($slug);
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

    public function test_dns_label_check_still_accepts_63_characters(): void
    {
        $this->assertTrue($this->policy()->isWellFormed(str_repeat('a', 63)));
    }

    public function test_slug_length_limit_matches_the_schema_prefix(): void
    {
        $this->assertSame(
            TenantSlugPolicy::MAX_SCHEMA_NAME_BYTES,
            mb_strlen(TenantSlugPolicy::SCHEMA_PREFIX) + TenantSlugPolicy::MAX_SLUG_LENGTH,
        );
    }

    #[DataProvider('tooLongSlugs')]
    public function test_rejects_slugs_whose_schema_name_would_be_truncated(string $slug): void
    {
        $this->expectException(InvalidTenantSlugException::class);
        $this->expectExceptionMessage('63-byte');

        $this->policy()->assertAssignable($slug);
    }

    public function test_schema_name_of_the_longest_assignable_slug_fits_exactly(): void
    {
        $policy = $this->policy();
        $slug   = str_repeat('a', TenantSlugPolicy::MAX_SLUG_LENGTH);

        $policy->assertAssignable($slug);

        $this->assertSame(63, mb_strlen($policy->schemaNameFor($slug)));
        $this->assertSame('tenant_acme_corp', $policy->schemaNameFor('acme-corp'));
    }

    /**
     * PostgreSQL truncates identifiers at 63 bytes, so two slugs differing only past
     * that point would share one physical schema. At most one may be assignable.
     */
    public function test_slugs_that_would_collide_after_truncation_are_not_both_assignable(): void
    {
        $policy = $this->policy();
        $first  = str_repeat('a', 56) . 'x';
        $second = str_repeat('a', 56) . 'y';

        $this->assertSame(
            mb_substr($policy->schemaNameFor($first), 0, 63),
            mb_substr($policy->schemaNameFor($second), 0, 63),
        );

        $assignable = 0;

        foreach ([$first, $second] as $slug) {
            try {
                $policy->assertAssignable($slug);
                $assignable++;
            } catch (InvalidTenantSlugException) {
            }
        }

        $this->assertLessThan(2, $assignable);
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
    /**
     * The schema name collapses runs of separators, so "web--hook" would derive the same
     * schema as "web-hook"; only one of the two may ever be assignable.
     */
    public function test_rejects_consecutive_hyphens_that_would_collide_after_derivation(): void
    {
        $policy = $this->policy();

        $this->assertSame($policy->schemaNameFor('web-hook'), $policy->schemaNameFor('web--hook'));

        $policy->assertAssignable('web-hook');

        $this->expectException(InvalidTenantSlugException::class);
        $this->expectExceptionMessage("contains '--'");
        $policy->assertAssignable('web--hook');
    }

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

    public function test_platform_subdomains_are_reserved(): void
    {
        config()->set('tenancy.default_tenant_slug', 'main');
        config()->set('tenancy.platform_subdomains', ['Saas', ' ops ', '']);

        $policy   = app(TenantSlugPolicy::class);
        $reserved = $policy->reservedSlugs();

        $this->assertContains('saas', $reserved);
        $this->assertContains('ops', $reserved);
        $this->assertNotContains('', $reserved);
        $this->assertTrue($policy->isReserved('saas'));

        $this->expectException(InvalidTenantSlugException::class);
        $policy->assertAssignable('saas');
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

    #[DataProvider('rejections')]
    public function test_a_refusal_carries_the_rule_that_caused_it(string $slug, SlugRejection $expected): void
    {
        try {
            $this->policy()->assertAssignable($slug);
            $this->fail('Expected the slug to be refused.');
        } catch (InvalidTenantSlugException $exception) {
            $this->assertSame($expected, $exception->reason);
            $this->assertSame(SlugRejection::Reserved === $expected, $exception->reservedByPlatform);
        }
    }

    private function policy(): TenantSlugPolicy
    {
        return new TenantSlugPolicy(['webhook', 'www', 'api']);
    }
}
