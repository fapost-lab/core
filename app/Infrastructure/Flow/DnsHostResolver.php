<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Flow\Call\Egress\HostResolverInterface;
use Closure;

/**
 * Resolves A and AAAA records with the DNS resolver, then falls back to the system resolver.
 *
 * `dns_get_record()` returns both families but ignores `/etc/hosts`, so when it finds
 * nothing the name is looked up with `gethostbynamel()` (IPv4, honours `/etc/hosts` and
 * container `extra_hosts`). The fallback widens nothing: every address still goes through the
 * address classifier and the operator allowlist. Neither call has a timeout of its own; they are
 * bounded by the system resolver settings and the guard's lookup deadline.
 */
final class DnsHostResolver implements HostResolverInterface
{
    /** @var Closure(string): (array<int, array<string, mixed>>|false) */
    private Closure $dnsLookup;

    /** @var Closure(string): (list<string>|false) */
    private Closure $systemLookup;

    /**
     * @param  (Closure(string): (array<int, array<string, mixed>>|false))|null  $dnsLookup
     * @param  (Closure(string): (list<string>|false))|null  $systemLookup
     */
    public function __construct(?Closure $dnsLookup = null, ?Closure $systemLookup = null)
    {
        $this->dnsLookup    = $dnsLookup ?? static fn (string $host): array|false => @dns_get_record($host, DNS_A | DNS_AAAA);
        $this->systemLookup = $systemLookup ?? static fn (string $host): array|false => @gethostbynamel($host);
    }

    public function resolve(string $host): array
    {
        $addresses = [];
        $records   = ($this->dnsLookup)($host);

        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && '' !== $address) {
                $addresses[] = $address;
            }
        }

        if ([] === $addresses) {
            $fallback = ($this->systemLookup)($host);

            foreach (is_array($fallback) ? $fallback : [] as $address) {
                if (is_string($address) && '' !== $address) {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
