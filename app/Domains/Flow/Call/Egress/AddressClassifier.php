<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call\Egress;

/**
 * Decides whether an IP address is a public one. Pure: no DNS, no configuration.
 *
 * An address is public only when two independent checks agree: the CIDR tables
 * below, which are a tested contract, and PHP's own global-range filter, which
 * catches whatever the tables miss. Addresses that embed an IPv4 address
 * (IPv4-mapped and -compatible, NAT64, 6to4) are judged by the embedded address.
 */
final readonly class AddressClassifier
{
    public const string REASON_UNSPECIFIED = 'unspecified';

    public const string REASON_PRIVATE = 'private';

    public const string REASON_LOOPBACK = 'loopback';

    public const string REASON_LINK_LOCAL = 'link_local';

    public const string REASON_MULTICAST = 'multicast';

    public const string REASON_RESERVED = 'reserved';

    public const string REASON_METADATA = 'metadata';

    public const string REASON_INVALID = 'invalid_host';

    /** @var array<string, string> */
    private const array IPV4_DENIED = [
        '0.0.0.0/8'          => self::REASON_UNSPECIFIED,
        '10.0.0.0/8'         => self::REASON_PRIVATE,
        '172.16.0.0/12'      => self::REASON_PRIVATE,
        '192.168.0.0/16'     => self::REASON_PRIVATE,
        '100.64.0.0/10'      => self::REASON_PRIVATE,
        '127.0.0.0/8'        => self::REASON_LOOPBACK,
        '169.254.0.0/16'     => self::REASON_LINK_LOCAL,
        '192.0.0.0/24'       => self::REASON_RESERVED,
        '192.0.2.0/24'       => self::REASON_RESERVED,
        '198.18.0.0/15'      => self::REASON_RESERVED,
        '198.51.100.0/24'    => self::REASON_RESERVED,
        '203.0.113.0/24'     => self::REASON_RESERVED,
        '192.88.99.0/24'     => self::REASON_RESERVED,
        '224.0.0.0/4'        => self::REASON_MULTICAST,
        '240.0.0.0/4'        => self::REASON_RESERVED,
        '255.255.255.255/32' => self::REASON_RESERVED,
    ];

    /** @var array<string, string> */
    private const array IPV6_DENIED = [
        '::/128'         => self::REASON_UNSPECIFIED,
        '::1/128'        => self::REASON_LOOPBACK,
        '64:ff9b:1::/48' => self::REASON_RESERVED,
        '2001::/23'      => self::REASON_RESERVED,
        '2001:db8::/32'  => self::REASON_RESERVED,
        '100::/64'       => self::REASON_RESERVED,
        'fc00::/7'       => self::REASON_PRIVATE,
        'fe80::/10'      => self::REASON_LINK_LOCAL,
        'fec0::/10'      => self::REASON_LINK_LOCAL,
        'ff00::/8'       => self::REASON_MULTICAST,
    ];

    /**
     * Cloud metadata endpoints. Never allowlistable, including the one that sits in
     * a public range (Azure WireServer).
     *
     * @var list<string>
     */
    private const array METADATA = [
        '169.254.169.254',
        '169.254.170.2',
        'fd00:ec2::254',
        '100.100.100.200',
        '168.63.129.16',
    ];

    /** @var list<array{Cidr, string}> */
    private array $ipv4Denied;

    /** @var list<array{Cidr, string}> */
    private array $ipv6Denied;

    /** @var list<string> */
    private array $metadataAddresses;

    public function __construct()
    {
        $this->ipv4Denied        = self::table(self::IPV4_DENIED);
        $this->ipv6Denied        = self::table(self::IPV6_DENIED);
        $this->metadataAddresses = array_map(
            static fn (string $address): string => (string) inet_pton($address),
            self::METADATA,
        );
    }

    /**
     * Why the textual address may not be reached, or null when it is public.
     */
    public function inspect(string $address): ?string
    {
        $packed = $this->normalize($address);

        if (null === $packed) {
            return self::REASON_INVALID;
        }

        if ($this->isMetadata($packed)) {
            return self::REASON_METADATA;
        }

        return $this->classify($packed);
    }

    /**
     * Packs a textual address, replacing an embedded IPv4 address for the wrapper formats.
     *
     * @return string|null 4 or 16 bytes, or null when the text is not an IP address
     */
    public function normalize(string $address): ?string
    {
        $packed = @inet_pton($address);

        if (false === $packed) {
            return null;
        }

        if (4 === mb_strlen($packed, '8bit')) {
            return $packed;
        }

        if ($packed === str_repeat("\x00", 15) . "\x01") {
            return $packed;
        }

        return $this->embeddedIpv4($packed) ?? $packed;
    }

    /**
     * @param  string  $packed  output of {@see normalize()}
     */
    public function isMetadata(string $packed): bool
    {
        return in_array($packed, $this->metadataAddresses, true);
    }

    /**
     * Denial reason for a normalised address, or null when it is public.
     *
     * @param  string  $packed  output of {@see normalize()}
     */
    public function classify(string $packed): ?string
    {
        $table = 4 === mb_strlen($packed, '8bit') ? $this->ipv4Denied : $this->ipv6Denied;

        foreach ($table as [$network, $reason]) {
            if ($network->contains($packed)) {
                return $reason;
            }
        }

        $text = inet_ntop($packed);

        if (false === $text || false === filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
            return self::REASON_RESERVED;
        }

        return null;
    }

    /**
     * @param  array<string, string>  $denied
     *
     * @return list<array{Cidr, string}>
     */
    private static function table(array $denied): array
    {
        $table = [];

        foreach ($denied as $notation => $reason) {
            $network = Cidr::parse($notation);

            if (null !== $network) {
                $table[] = [$network, $reason];
            }
        }

        return $table;
    }

    /**
     * Byte-wise string functions on purpose: `$packed` is binary, not UTF-8.
     */
    private function embeddedIpv4(string $packed): ?string
    {
        $head  = mb_substr($packed, 0, 12, '8bit');
        $zeros = str_repeat("\x00", 12);

        // ::/96 (IPv4-compatible, includes ::) and ::ffff:0:0/96 (IPv4-mapped)
        if ($head === $zeros || $head === str_repeat("\x00", 10) . "\xff\xff") {
            return mb_substr($packed, 12, 4, '8bit');
        }

        // 64:ff9b::/96 (NAT64)
        if ($head === "\x00\x64\xff\x9b" . str_repeat("\x00", 8)) {
            return mb_substr($packed, 12, 4, '8bit');
        }

        // 2002::/16 (6to4)
        if ("\x20\x02" === mb_substr($packed, 0, 2, '8bit')) {
            return mb_substr($packed, 2, 4, '8bit');
        }

        return null;
    }
}
