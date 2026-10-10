<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Call\Egress;

use App\Domains\Flow\Call\Egress\EgressPolicy;
use PHPUnit\Framework\TestCase;

final class EgressPolicyTest extends TestCase
{
    public function test_empty_allowlist_allows_nothing(): void
    {
        $policy = EgressPolicy::fromConfig('', 5, null);

        $this->assertFalse($policy->allowsHost('crm.local'));
        $this->assertFalse($policy->allowsAddress((string) inet_pton('10.0.0.1')));
        $this->assertFalse($policy->allowsEverything());
        $this->assertNull($policy->proxy);
    }

    public function test_parses_networks_addresses_and_host_names(): void
    {
        $policy = EgressPolicy::fromConfig(' 10.20.0.0/16 , 192.168.1.5,fd00::/8, CRM.local ,', 3, ' http://p:3128 ');

        $this->assertTrue($policy->allowsAddress((string) inet_pton('10.20.9.9')));
        $this->assertFalse($policy->allowsAddress((string) inet_pton('10.21.0.1')));
        $this->assertTrue($policy->allowsAddress((string) inet_pton('192.168.1.5')));
        $this->assertFalse($policy->allowsAddress((string) inet_pton('192.168.1.6')));
        $this->assertTrue($policy->allowsAddress((string) inet_pton('fd00::1')));
        $this->assertFalse($policy->allowsAddress((string) inet_pton('fe80::1')));
        $this->assertTrue($policy->allowsHost('crm.local'));
        $this->assertSame(3, $policy->maxRedirects);
        $this->assertSame('http://p:3128', $policy->proxy);
        $this->assertSame([], $policy->invalidEntries);
    }

    public function test_invalid_entries_are_reported_and_ignored(): void
    {
        $policy = EgressPolicy::fromConfig('10.0.0.0/99,bad host,*.example.com,ok.example.com', 5, '   ');

        $this->assertSame(['10.0.0.0/99', 'bad host', '*.example.com'], $policy->invalidEntries);
        $this->assertTrue($policy->allowsHost('ok.example.com'));
        $this->assertFalse($policy->allowsAddress((string) inet_pton('10.0.0.1')));
        $this->assertNull($policy->proxy);
    }

    public function test_detects_an_allowlist_that_covers_everything(): void
    {
        $this->assertTrue(EgressPolicy::fromConfig('0.0.0.0/0', 5, null)->allowsEverything());
        $this->assertTrue(EgressPolicy::fromConfig('::/0', 5, null)->allowsEverything());
        $this->assertFalse(EgressPolicy::fromConfig('10.0.0.0/8', 5, null)->allowsEverything());
    }
}
