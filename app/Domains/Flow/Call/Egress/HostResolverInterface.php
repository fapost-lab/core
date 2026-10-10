<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call\Egress;

/**
 * Port for the DNS lookup behind the egress guard. The only implementation that
 * touches the network lives in the infrastructure layer; tests bind a fake.
 */
interface HostResolverInterface
{
    /**
     * Every A and AAAA address of the host, textual. An empty list means the name
     * does not resolve.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
