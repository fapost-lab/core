<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Flow;

use App\Infrastructure\Flow\DnsHostResolver;
use PHPUnit\Framework\TestCase;

final class DnsHostResolverTest extends TestCase
{
    public function test_returns_dns_records_of_both_families_without_asking_the_system_resolver(): void
    {
        $resolver = new DnsHostResolver(
            static fn (): array => [['ip' => '93.184.216.34'], ['ipv6' => '2606:2800::1'], ['ip' => '93.184.216.34']],
            static fn (): never => self::fail('The system resolver must not be used when DNS answered.'),
        );

        $this->assertSame(['93.184.216.34', '2606:2800::1'], $resolver->resolve('api.example.com'));
    }

    public function test_falls_back_to_the_system_resolver_when_dns_has_no_answer(): void
    {
        $resolver = new DnsHostResolver(static fn (): array => [], static fn (): array => ['127.0.0.1']);

        $this->assertSame(['127.0.0.1'], $resolver->resolve('mock.local'));
    }

    public function test_a_failed_dns_lookup_also_falls_back(): void
    {
        $resolver = new DnsHostResolver(static fn (): bool => false, static fn (): array => ['10.0.0.4']);

        $this->assertSame(['10.0.0.4'], $resolver->resolve('crm.local'));
    }

    public function test_returns_nothing_when_neither_resolver_knows_the_name(): void
    {
        $resolver = new DnsHostResolver(static fn (): bool => false, static fn (): bool => false);

        $this->assertSame([], $resolver->resolve('nowhere.example.com'));
    }
}
