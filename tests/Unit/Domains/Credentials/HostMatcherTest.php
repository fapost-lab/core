<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Credentials;

use App\Domains\Credentials\HostMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostMatcherTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function validPatterns(): array
    {
        return [
            'host'          => ['api.example.com'],
            'upper case'    => ['API.Example.COM'],
            'single label'  => ['crm-internal'],
            'wildcard'      => ['*.example.com'],
            'ipv4'          => ['203.0.113.7'],
            'idn'           => ['пример.рф'],
            'idn wildcard'  => ['*.пример.рф'],
            'trailing dot'  => ['example.com.'],
            'hyphen inside' => ['my-api.example.com'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPatterns(): array
    {
        return [
            'empty'              => [''],
            'bare wildcard'      => ['*'],
            'wildcard one label' => ['*.com'],
            'wildcard middle'    => ['api.*.example.com'],
            'wildcard suffix'    => ['api.*'],
            'wildcard no dot'    => ['*example.com'],
            'scheme'             => ['https://example.com'],
            'port'               => ['example.com:8080'],
            'path'               => ['example.com/api'],
            'space'              => ['exa mple.com'],
            'user info'          => ['user@example.com'],
            'ipv6'               => ['[::1]'],
            'partial ipv4'       => ['1.2.3'],
            'big ipv4'           => ['999.1.1.1'],
            'leading hyphen'     => ['-a.example.com'],
            'empty label'        => ['a..example.com'],
            'underscore'         => ['a_b.example.com'],
            'too long label'     => [str_repeat('a', 64) . '.example.com'],
        ];
    }

    #[DataProvider('validPatterns')]
    public function test_a_valid_pattern_is_accepted(string $pattern): void
    {
        $this->assertTrue(HostMatcher::isValidPattern($pattern));
    }

    #[DataProvider('invalidPatterns')]
    public function test_an_invalid_pattern_is_refused(string $pattern): void
    {
        $this->assertFalse(HostMatcher::isValidPattern($pattern));
    }

    public function test_normalize_lowercases_converts_to_punycode_and_drops_the_trailing_dot(): void
    {
        $this->assertSame('api.example.com', HostMatcher::normalize(' API.Example.com. '));
        $this->assertSame('xn--e1afmkfd.xn--p1ai', HostMatcher::normalize('Пример.РФ'));
        $this->assertSame('*.xn--e1afmkfd.xn--p1ai', HostMatcher::normalize('*.пример.рф'));
    }

    public function test_an_exact_host_matches_only_itself_in_any_case(): void
    {
        $patterns = ['api.example.com'];

        $this->assertTrue(HostMatcher::matches('api.example.com', $patterns));
        $this->assertTrue(HostMatcher::matches('API.EXAMPLE.COM', $patterns));
        $this->assertTrue(HostMatcher::matches('api.example.com.', $patterns));
        $this->assertFalse(HostMatcher::matches('example.com', $patterns));
        $this->assertFalse(HostMatcher::matches('x.api.example.com', $patterns));
        $this->assertFalse(HostMatcher::matches('api.example.com.evil.test', $patterns));
    }

    public function test_a_wildcard_matches_subdomains_at_any_depth_but_not_the_domain_itself(): void
    {
        $patterns = ['*.example.com'];

        $this->assertTrue(HostMatcher::matches('a.example.com', $patterns));
        $this->assertTrue(HostMatcher::matches('a.b.example.com', $patterns));
        $this->assertFalse(HostMatcher::matches('example.com', $patterns));
        $this->assertFalse(HostMatcher::matches('badexample.com', $patterns));
        $this->assertFalse(HostMatcher::matches('a.example.com.evil.test', $patterns));
    }

    public function test_an_idn_host_matches_its_punycode_pattern_and_back(): void
    {
        $this->assertTrue(HostMatcher::matches('api.пример.рф', ['*.xn--e1afmkfd.xn--p1ai']));
        $this->assertTrue(HostMatcher::matches('api.xn--e1afmkfd.xn--p1ai', ['api.пример.рф']));
    }

    public function test_an_invalid_pattern_or_host_matches_nothing(): void
    {
        $this->assertFalse(HostMatcher::matches('example.com', ['*']));
        $this->assertFalse(HostMatcher::matches('example.com', []));
        $this->assertFalse(HostMatcher::matches('', ['example.com']));
        $this->assertFalse(HostMatcher::matches('example.com:8080', ['example.com']));
        $this->assertFalse(HostMatcher::matches('*.example.com', ['*.example.com']));
    }

    public function test_any_pattern_of_the_list_may_match(): void
    {
        $this->assertTrue(HostMatcher::matches('b.test', ['a.test', 'b.test']));
    }

    public function test_an_exact_host_is_covered_by_itself_or_a_wildcard_over_it(): void
    {
        $this->assertTrue(HostMatcher::covers('API.example.com.', ['api.example.com']));
        $this->assertTrue(HostMatcher::covers('api.example.com', ['*.example.com']));
        $this->assertFalse(HostMatcher::covers('example.com', ['*.example.com']));
        $this->assertFalse(HostMatcher::covers('collector.attacker.test', ['api.example.com']));
    }

    public function test_a_wildcard_is_covered_only_by_the_same_or_a_wider_wildcard(): void
    {
        $this->assertTrue(HostMatcher::covers('*.example.com', ['*.example.com']));
        $this->assertTrue(HostMatcher::covers('*.eu.example.com', ['*.example.com']));
        $this->assertFalse(HostMatcher::covers('*.example.com', ['api.example.com', 'b.example.com']));
        $this->assertFalse(HostMatcher::covers('*.example.com', ['*.eu.example.com']));
        $this->assertFalse(HostMatcher::covers('*.example.com', ['example.com']));
    }

    public function test_an_invalid_pattern_is_never_covered(): void
    {
        $this->assertFalse(HostMatcher::covers('*', ['*.example.com']));
        $this->assertFalse(HostMatcher::covers('https://api.example.com', ['api.example.com']));
    }
}
