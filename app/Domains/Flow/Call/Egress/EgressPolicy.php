<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call\Egress;

/**
 * Operator-level egress settings, read once from `config('flow.egress')`.
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
     */
    public function __construct(
        public array $allowedNetworks = [],
        public array $allowedHosts = [],
        public int $maxRedirects = 5,
        public ?string $proxy = null,
        public array $invalidEntries = [],
    ) {
    }

    /**
     * @param  string|null  $allow  comma-separated CIDR ranges, addresses and host names
     */
    public static function fromConfig(?string $allow, int $maxRedirects, ?string $proxy): self
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
        );
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
