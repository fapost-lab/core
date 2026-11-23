<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call\Egress;

/**
 * Operator-level egress settings, read once from `config('flow.egress')`, together with the installation's own hosts
 * (the `app.url` host and `tenancy.base_domain`, see FlowServiceProvider).
 *
 * Holds values rather than the config repository so a long-lived worker never
 * reads configuration mid-job.
 */
final readonly class EgressPolicy
{
    /**
     * @param  list<Cidr>  $allowedNetworks
     * @param  list<string>  $allowedHosts  lower-case host names
     * @param  list<string>  $invalidEntries  allowlist entries that were neither a network nor a host name
     * @param  list<string>  $ownHosts  lower-case hosts this installation serves; their subdomains are its too
     */
    public function __construct(
        public array $allowedNetworks = [],
        public array $allowedHosts = [],
        public int $maxRedirects = 5,
        public ?string $proxy = null,
        public array $invalidEntries = [],
        public array $ownHosts = [],
    ) {
    }

    /**
     * @param  string|null  $allow  comma-separated CIDR ranges, addresses and host names
     * @param  list<string|null>  $ownHosts  the installation's own hosts (the `APP_URL` host, the tenancy base domain)
     */
    public static function fromConfig(?string $allow, int $maxRedirects, ?string $proxy, array $ownHosts = []): self
    {
        $networks = [];
        $hosts    = [];
        $invalid  = [];

        foreach (explode(',', $allow ?? '') as $entry) {
            $entry = mb_strtolower(mb_trim($entry));

            if ('' === $entry) {
                continue;
            }

            $network = Cidr::parse($entry);

            if (null !== $network) {
                $networks[] = $network;
            } elseif (1 === preg_match('/^[a-z0-9_]([a-z0-9_.-]*[a-z0-9_])?$/', $entry) && ! str_contains($entry, '/')) {
                $hosts[] = $entry;
            } else {
                $invalid[] = $entry;
            }
        }

        $proxy = null === $proxy ? null : mb_trim($proxy);

        return new self(
            allowedNetworks: $networks,
            allowedHosts: $hosts,
            maxRedirects: max(0, $maxRedirects),
            proxy: '' === $proxy ? null : $proxy,
            invalidEntries: $invalid,
            ownHosts: array_values(array_unique(array_filter(
                array_map(static fn (?string $host): string => mb_rtrim(mb_strtolower(mb_trim((string) $host)), '.'), $ownHosts),
                static fn (string $host): bool => '' !== $host,
            ))),
        );
    }

    /**
     * Whether a URL is a flow webhook trigger of this installation (`/hooks/flow/…` on its own host or a subdomain of
     * it): a call node posting there would start a flow whose call node may post back — a loop of runs with no event in
     * it to count. A guard against the plain loop, not a boundary: a DNS alias or an outside relay gets past it.
     */
    public function isOwnFlowWebhook(string $host, string $path): bool
    {
        // The router matches the decoded path, and a front controller in front of it (`/index.php/hooks/flow/…`) too.
        if (1 !== preg_match('#(^|/)hooks/+flow/#i', rawurldecode($path))) {
            return false;
        }

        $host = mb_rtrim(mb_strtolower($host), '.');

        foreach ($this->ownHosts as $own) {
            if ($host === $own || str_ends_with($host, '.' . $own)) {
                return true;
            }
        }

        return false;
    }

    public function allowsHost(string $host): bool
    {
        return in_array($host, $this->allowedHosts, true);
    }

    /**
     * @param  string  $address  packed, normalised address
     */
    public function allowsAddress(string $address): bool
    {
        foreach ($this->allowedNetworks as $network) {
            if ($network->contains($address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the allowlist covers all of IPv4 or IPv6, which switches the guard off in practice.
     */
    public function allowsEverything(): bool
    {
        foreach ($this->allowedNetworks as $network) {
            if ($network->isEverything()) {
                return true;
            }
        }

        return false;
    }
}
