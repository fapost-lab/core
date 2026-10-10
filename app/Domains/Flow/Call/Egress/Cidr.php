<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call\Egress;

/**
 * One IPv4 or IPv6 network, matched on the packed binary form of an address.
 */
final readonly class Cidr
{
    private function __construct(
        private string $network,
        private int $prefix,
    ) {
    }

    /**
     * Parses `a.b.c.d/len`, `a:b::/len` or a bare address (a host route).
     */
    public static function parse(string $notation): ?self
    {
        $parts   = explode('/', mb_trim($notation), 2);
        $network = @inet_pton($parts[0]);

        if (false === $network) {
            return null;
        }

        $maxPrefix = 8 * mb_strlen($network, '8bit');

        if (! isset($parts[1])) {
            return new self($network, $maxPrefix);
        }

        if (1 !== preg_match('/^\d{1,3}$/', $parts[1]) || (int) $parts[1] > $maxPrefix) {
            return null;
        }

        return new self($network, (int) $parts[1]);
    }

    /**
     * @param  string  $address  packed address as returned by `inet_pton()`
     */
    public function contains(string $address): bool
    {
        if (mb_strlen($address, '8bit') !== mb_strlen($this->network, '8bit')) {
            return false;
        }

        $wholeBytes = intdiv($this->prefix, 8);

        if (0 !== $wholeBytes && mb_substr($address, 0, $wholeBytes, '8bit') !== mb_substr($this->network, 0, $wholeBytes, '8bit')) {
            return false;
        }

        $remainingBits = $this->prefix % 8;

        if (0 === $remainingBits) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($this->network[$wholeBytes]) & $mask);
    }

    public function isEverything(): bool
    {
        return 0 === $this->prefix;
    }
}
