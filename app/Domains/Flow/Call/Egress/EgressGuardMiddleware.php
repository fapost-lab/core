<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call\Egress;

use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle middleware that keeps the call node on public addresses.
 *
 * It sits inside Guzzle's redirect middleware, so it runs for the first request
 * and again for every redirect hop. Per request it checks the scheme and the host
 * (numeric host forms that curl would parse as an address are refused), resolves
 * a host name, refuses the request when any resolved address is not public, and
 * pins the connection to exactly the addresses that were checked through
 * `CURLOPT_RESOLVE`, so curl never resolves the name a second time.
 *
 * It keeps no state between requests.
 */
final readonly class EgressGuardMiddleware
{
    /** Longest accepted name lookup; the HTTP timeout (10s by default) starts only after it. */
    private const int LOOKUP_DEADLINE_SECONDS = 8;

    public function __construct(
        private HostResolverInterface $resolver,
        private AddressClassifier $classifier,
        private EgressPolicy $policy,
    ) {
    }

    /**
     * @return callable(RequestInterface, array<string, mixed>): mixed
     */
    public function __invoke(callable $handler): callable
    {
        return fn (RequestInterface $request, array $options): mixed => $handler($request, $this->guard($request, $options));
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @return array<string, mixed>
     */
    private function guard(RequestInterface $request, array $options): array
    {
        $uri    = $request->getUri();
        $scheme = mb_strtolower($uri->getScheme());
        $host   = mb_strtolower($uri->getHost());

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new EgressDeniedException($host, 'scheme_not_allowed');
        }

        $literal = $this->literalAddress($host);

        if (null !== $literal) {
            $this->assertPublic($host, [$literal], false);

            return $options;
        }

        if (! $this->isHostName($host)) {
            throw new EgressDeniedException($host, AddressClassifier::REASON_INVALID);
        }

        $startedAt = hrtime(true);
        $addresses = array_values(array_unique($this->resolver->resolve($host)));

        // The lookup is not covered by the HTTP timeout; a slow resolver must not eat the session lock TTL.
        if ((hrtime(true) - $startedAt) / 1e9 > self::LOOKUP_DEADLINE_SECONDS) {
            throw new ConnectException("Resolving {$host} took too long.", $request);
        }

        if ([] === $addresses) {
            // Never fall through to curl's own lookup: it could answer differently.
            throw new ConnectException("Could not resolve host: {$host}", $request);
        }

        $this->assertPublic($host, $addresses, $this->policy->allowsHost($host));

        // Behind an operator proxy curl resolves the proxy, not the target, so a pin means nothing.
        if (null !== $this->policy->proxy) {
            return $options;
        }

        $port    = $uri->getPort() ?? ('https' === $scheme ? 443 : 80);
        $entries = array_map(
            static fn (string $address): string => str_contains($address, ':') ? "[{$address}]" : $address,
            $addresses,
        );

        $curl                  = is_array($options['curl'] ?? null) ? $options['curl'] : [];
        $curl[CURLOPT_RESOLVE] = ["{$host}:{$port}:" . implode(',', $entries)];
        $options['curl']       = $curl;

        return $options;
    }

    /**
     * @param  list<string>  $addresses
     * @param  bool  $hostAllowed  the host name is on the operator allowlist: only metadata addresses stay refused
     */
    private function assertPublic(string $host, array $addresses, bool $hostAllowed): void
    {
        foreach ($addresses as $address) {
            $packed = $this->classifier->normalize($address);

            if (null === $packed) {
                throw new EgressDeniedException($host, AddressClassifier::REASON_INVALID, $addresses);
            }

            if ($this->classifier->isMetadata($packed)) {
                throw new EgressDeniedException($host, AddressClassifier::REASON_METADATA, $addresses);
            }

            if ($hostAllowed) {
                continue;
            }

            $reason = $this->classifier->classify($packed);

            if (null !== $reason && ! $this->policy->allowsAddress($packed)) {
                throw new EgressDeniedException($host, $reason, $addresses);
            }
        }
    }

    /**
     * The address when the host is an IP literal; null for a name. Anything that
     * looks like an address without being a canonical one is refused outright.
     */
    private function literalAddress(string $host): ?string
    {
        if (str_starts_with($host, '[')) {
            $inner = mb_substr($host, 1, -1);

            if (! str_ends_with($host, ']') || ! str_contains($inner, ':') || false === @inet_pton($inner)) {
                throw new EgressDeniedException($host, AddressClassifier::REASON_INVALID);
            }

            return $inner;
        }

        if ('' === $host) {
            throw new EgressDeniedException($host, AddressClassifier::REASON_INVALID);
        }

        if (! $this->looksNumeric($host)) {
            return null;
        }

        $packed = @inet_pton($host);

        // glibc and curl read `2130706433`, `0x7f.1` or `127.1` as IPv4: refuse them rather than guess.
        if (false === $packed || inet_ntop($packed) !== $host) {
            throw new EgressDeniedException($host, AddressClassifier::REASON_INVALID);
        }

        return $host;
    }

    /**
     * True when every dot-separated label is a decimal or hexadecimal number, i.e. the
     * host could be parsed as an address by inet_aton.
     */
    private function looksNumeric(string $host): bool
    {
        foreach (explode('.', $host) as $label) {
            if (1 !== preg_match('/^(0[xX][0-9a-fA-F]*|[0-9]*)$/', $label)) {
                return false;
            }
        }

        return true;
    }

    private function isHostName(string $host): bool
    {
        return 1 === preg_match('/^[a-z0-9_]([a-z0-9_.-]*[a-z0-9_])?\.?$/', $host);
    }
}
