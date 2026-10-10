<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Call\Egress;

use App\Domains\Flow\Call\Egress\AddressClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressClassifierTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function deniedAddresses(): array
    {
        return [
            'zero network'           => ['0.0.0.0', 'unspecified'],
            'zero network end'       => ['0.255.255.255', 'unspecified'],
            'class A private'        => ['10.1.2.3', 'private'],
            'class B private start'  => ['172.16.0.0', 'private'],
            'class B private end'    => ['172.31.255.255', 'private'],
            'class C private'        => ['192.168.1.1', 'private'],
            'cgnat'                  => ['100.64.0.1', 'private'],
            'cgnat end'              => ['100.127.255.255', 'private'],
            'loopback'               => ['127.0.0.1', 'loopback'],
            'loopback end'           => ['127.255.255.255', 'loopback'],
            'link local'             => ['169.254.1.1', 'link_local'],
            'ietf protocol block'    => ['192.0.0.8', 'reserved'],
            'test net 1'             => ['192.0.2.1', 'reserved'],
            'benchmarking'           => ['198.19.255.255', 'reserved'],
            'test net 2'             => ['198.51.100.9', 'reserved'],
            'test net 3'             => ['203.0.113.9', 'reserved'],
            '6to4 relay anycast'     => ['192.88.99.1', 'reserved'],
            'multicast'              => ['224.0.0.1', 'multicast'],
            'future use'             => ['240.0.0.1', 'reserved'],
            'broadcast'              => ['255.255.255.255', 'reserved'],
            'aws and gcp metadata'   => ['169.254.169.254', 'metadata'],
            'ecs task metadata'      => ['169.254.170.2', 'metadata'],
            'alibaba metadata'       => ['100.100.100.200', 'metadata'],
            'azure wireserver'       => ['168.63.129.16', 'metadata'],
            'aws ipv6 metadata'      => ['fd00:ec2::254', 'metadata'],
            'ipv6 unspecified'       => ['::', 'unspecified'],
            'ipv6 loopback'          => ['::1', 'loopback'],
            'ipv6 unique local'      => ['fd12:3456::1', 'private'],
            'ipv6 unique local fc'   => ['fc00::1', 'private'],
            'ipv6 link local'        => ['fe80::1', 'link_local'],
            'ipv6 site local'        => ['fec0::1', 'link_local'],
            'ipv6 multicast'         => ['ff02::1', 'multicast'],
            'ipv6 documentation'     => ['2001:db8::1', 'reserved'],
            'teredo'                 => ['2001:0:4136:e378:8000:63bf:3fff:fdd2', 'reserved'],
            'ietf protocol block v6' => ['2001:1::1', 'reserved'],
            'discard prefix'         => ['100::1', 'reserved'],
            'nat64 local use'        => ['64:ff9b:1::1', 'reserved'],
            'mapped loopback'        => ['::ffff:127.0.0.1', 'loopback'],
            'mapped loopback hex'    => ['::ffff:7f00:1', 'loopback'],
            'mapped private'         => ['::ffff:10.0.0.1', 'private'],
            'mapped metadata'        => ['::ffff:169.254.169.254', 'metadata'],
            'compatible loopback'    => ['::7f00:1', 'loopback'],
            'nat64 private'          => ['64:ff9b::10.0.0.1', 'private'],
            '6to4 loopback'          => ['2002:7f00:1::1', 'loopback'],
            '6to4 private'           => ['2002:c0a8:101::1', 'private'],
            'not an address'         => ['not-an-ip', 'invalid_host'],
            'zone id'                => ['fe80::1%eth0', 'invalid_host'],
            'non canonical ipv4'     => ['127.1', 'invalid_host'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicAddresses(): array
    {
        return [
            'just below class B private' => ['172.15.255.255'],
            'just above class B private' => ['172.32.0.0'],
            'just below cgnat'           => ['100.63.255.255'],
            'just above cgnat'           => ['100.128.0.0'],
            'just below class A private' => ['9.255.255.255'],
            'just above class A private' => ['11.0.0.0'],
            'below link local'           => ['169.253.255.255'],
            'above link local'           => ['169.255.0.0'],
            'public v4'                  => ['93.184.216.34'],
            'public dns v4'              => ['8.8.8.8'],
            'public v6'                  => ['2606:2800:220:1:248:1893:25c8:1946'],
            'public dns v6'              => ['2001:4860:4860::8888'],
            'mapped public'              => ['::ffff:8.8.8.8'],
            'nat64 public'               => ['64:ff9b::808:808'],
            '6to4 public'                => ['2002:808:808::1'],
        ];
    }

    #[DataProvider('deniedAddresses')]
    public function test_denies_non_public_addresses_with_a_reason(string $address, string $reason): void
    {
        $this->assertSame($reason, (new AddressClassifier())->inspect($address));
    }

    #[DataProvider('publicAddresses')]
    public function test_allows_public_addresses(string $address): void
    {
        $this->assertNull((new AddressClassifier())->inspect($address));
    }

    public function test_normalize_unwraps_an_embedded_ipv4_address(): void
    {
        $classifier = new AddressClassifier();

        $this->assertSame(inet_pton('127.0.0.1'), $classifier->normalize('::ffff:127.0.0.1'));
        $this->assertSame(inet_pton('::1'), $classifier->normalize('::1'));
        $this->assertNull($classifier->normalize('127.1'));
    }
}
