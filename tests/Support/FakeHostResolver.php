<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Flow\Call\Egress\HostResolverInterface;

/**
 * Test double for DNS: no host in a test touches the network.
 *
 * Every name resolves to a public address unless the test says otherwise, so
 * existing tests that fake `api.example.com` keep working unchanged.
 */
final class FakeHostResolver implements HostResolverInterface
{
    public const string PUBLIC_ADDRESS = '93.184.216.34';

    /** @var list<string> */
    public array $lookups = [];

    /** @var array<string, list<string>> */
    private array $records = [];

    /**
     * @param  list<string>  $addresses  an empty list makes the name unresolvable
     */
    public function with(string $host, array $addresses): self
    {
        $this->records[mb_strtolower($host)] = $addresses;

        return $this;
    }

    public function resolve(string $host): array
    {
        $this->lookups[] = $host;

        return $this->records[mb_strtolower($host)] ?? [self::PUBLIC_ADDRESS];
    }
}
