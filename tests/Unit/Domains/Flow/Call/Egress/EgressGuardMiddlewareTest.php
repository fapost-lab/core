<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Call\Egress;

use App\Domains\Flow\Call\Egress\AddressClassifier;
use App\Domains\Flow\Call\Egress\EgressDeniedException;
use App\Domains\Flow\Call\Egress\EgressGuardMiddleware;
use App\Domains\Flow\Call\Egress\EgressPolicy;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeHostResolver;

final class EgressGuardMiddlewareTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $forwardedOptions = null;

    private FakeHostResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->forwardedOptions = null;
        $this->resolver         = new FakeHostResolver();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedTargets(): array
    {
        return [
            'ftp scheme'                => ['ftp://example.com/file', 'scheme_not_allowed'],
            'file scheme'               => ['file:///etc/passwd', 'scheme_not_allowed'],
            'loopback literal'          => ['http://127.0.0.1/', 'loopback'],
            'private literal'           => ['http://10.0.0.5:8080/x', 'private'],
            'metadata literal'          => ['http://169.254.169.254/latest/meta-data/', 'metadata'],
            'ipv6 loopback literal'     => ['http://[::1]/', 'loopback'],
            'ipv4 mapped literal'       => ['http://[::ffff:127.0.0.1]/', 'loopback'],
            'ipv6 zone id'              => ['http://[fe80::1%25eth0]/', 'invalid_host'],
            'decimal ipv4'              => ['http://2130706433/', 'invalid_host'],
            'hex ipv4'                  => ['http://0x7f.1/', 'invalid_host'],
            'short ipv4'                => ['http://127.1/', 'invalid_host'],
            'octal ipv4'                => ['http://0177.0.0.1/', 'invalid_host'],
            'trailing dot ipv4'         => ['http://127.0.0.1./', 'invalid_host'],
            'non ascii host'            => ['http://exämple.com/', 'invalid_host'],
            'name with private address' => ['https://internal.example.com/', 'private'],
        ];
    }

    #[DataProvider('refusedTargets')]
    public function test_refuses_target_without_forwarding_the_request(string $url, string $reason): void
    {
        $this->resolver->with('internal.example.com', ['10.0.0.9']);

        try {
            $this->send(new Request('GET', $url));
            $this->fail('The request should have been refused.');
        } catch (EgressDeniedException $exception) {
            $this->assertSame($reason, $exception->reason);
        }

        $this->assertNull($this->forwardedOptions, 'A refused request must never reach the next handler.');
    }

    public function test_a_single_denied_address_refuses_the_whole_answer(): void
    {
        $this->resolver->with('mixed.example.com', ['93.184.216.34', '10.0.0.9']);

        $this->expectException(EgressDeniedException::class);

        $this->send(new Request('GET', 'https://mixed.example.com/'));
    }

    public function test_the_exception_carries_resolved_addresses_for_the_operator_log(): void
    {
        $this->resolver->with('rebind.example.com', ['127.0.0.1']);

        try {
            $this->send(new Request('GET', 'https://rebind.example.com/'));
            $this->fail('The request should have been refused.');
        } catch (EgressDeniedException $exception) {
            $this->assertSame('rebind.example.com', $exception->host);
            $this->assertSame(['127.0.0.1'], $exception->addresses);
        }
    }

    public function test_a_name_that_does_not_resolve_is_a_connection_failure_not_a_pass(): void
    {
        $this->resolver->with('nowhere.example.com', []);

        $this->expectException(ConnectException::class);

        $this->send(new Request('GET', 'https://nowhere.example.com/'));
    }

    public function test_pins_the_connection_to_the_checked_addresses_with_the_default_port(): void
    {
        $this->resolver->with('api.example.com', ['93.184.216.34', '2606:2800:220:1::1']);

        $this->send(new Request('GET', 'https://api.example.com/users'));

        $this->assertSame(
            ['api.example.com:443:93.184.216.34,[2606:2800:220:1::1]'],
            $this->forwardedOptions['curl'][CURLOPT_RESOLVE] ?? null,
        );
    }

    public function test_pins_with_the_explicit_port_and_keeps_other_curl_options(): void
    {
        $this->send(new Request('GET', 'http://api.example.com:8080/'), [CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);

        $this->assertSame(['api.example.com:8080:' . FakeHostResolver::PUBLIC_ADDRESS], $this->forwardedOptions['curl'][CURLOPT_RESOLVE]);
        $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $this->forwardedOptions['curl'][CURLOPT_PROTOCOLS]);
    }

    public function test_default_http_port_is_80(): void
    {
        $this->send(new Request('GET', 'http://api.example.com/'));

        $this->assertSame(['api.example.com:80:' . FakeHostResolver::PUBLIC_ADDRESS], $this->forwardedOptions['curl'][CURLOPT_RESOLVE]);
    }

    public function test_a_public_literal_passes_without_a_lookup_or_a_pin(): void
    {
        $this->send(new Request('GET', 'http://93.184.216.34/'));

        $this->assertNotNull($this->forwardedOptions);
        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $this->forwardedOptions['curl'] ?? []);
        $this->assertSame([], $this->resolver->lookups);
    }

    public function test_an_allowed_network_lets_a_private_address_through(): void
    {
        $this->resolver->with('crm.internal.test', ['10.20.0.7']);

        $this->send(new Request('GET', 'http://crm.internal.test/'), policy: EgressPolicy::fromConfig('10.20.0.0/16', 5, null));
        $this->assertSame(['crm.internal.test:80:10.20.0.7'], $this->forwardedOptions['curl'][CURLOPT_RESOLVE]);

        $this->forwardedOptions = null;
        $this->send(new Request('GET', 'http://10.20.255.1/'), policy: EgressPolicy::fromConfig('10.20.0.0/16', 5, null));
        $this->assertNotNull($this->forwardedOptions);
    }

    public function test_an_allowed_network_does_not_cover_its_neighbours(): void
    {
        $this->expectException(EgressDeniedException::class);

        $this->send(new Request('GET', 'http://10.21.0.1/'), policy: EgressPolicy::fromConfig('10.20.0.0/16', 5, null));
    }

    public function test_an_allowed_host_name_skips_classification_but_is_still_pinned(): void
    {
        $this->resolver->with('crm.local', ['192.168.1.20']);

        $this->send(new Request('GET', 'http://CRM.local/'), policy: EgressPolicy::fromConfig('crm.local', 5, null));

        $this->assertSame(['crm.local:80:192.168.1.20'], $this->forwardedOptions['curl'][CURLOPT_RESOLVE]);
    }

    public function test_a_host_allowlist_entry_does_not_allow_a_literal_address(): void
    {
        $this->expectException(EgressDeniedException::class);

        $this->send(new Request('GET', 'http://192.168.1.20/'), policy: EgressPolicy::fromConfig('crm.local', 5, null));
    }

    public function test_metadata_addresses_cannot_be_allowlisted_by_network_or_by_host(): void
    {
        $policy = EgressPolicy::fromConfig('0.0.0.0/0,::/0,sneaky.example.com', 5, null);
        $this->resolver->with('sneaky.example.com', ['169.254.169.254']);
        $this->resolver->with('azure.example.com', ['168.63.129.16']);

        foreach (['http://169.254.169.254/', 'http://[fd00:ec2::254]/', 'http://sneaky.example.com/', 'http://azure.example.com/'] as $url) {
            try {
                $this->send(new Request('GET', $url), policy: $policy);
                $this->fail("{$url} should stay refused.");
            } catch (EgressDeniedException $exception) {
                $this->assertSame('metadata', $exception->reason, $url);
            }
        }
    }

    public function test_behind_an_operator_proxy_the_target_is_checked_but_not_pinned(): void
    {
        $policy = EgressPolicy::fromConfig('', 5, 'http://proxy.internal:3128');

        $this->send(new Request('GET', 'https://api.example.com/'), policy: $policy);

        $this->assertNotNull($this->forwardedOptions);
        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $this->forwardedOptions['curl'] ?? []);

        $this->resolver->with('private.example.com', ['10.0.0.1']);
        $this->expectException(EgressDeniedException::class);
        $this->send(new Request('GET', 'https://private.example.com/'), policy: $policy);
    }

    /**
     * @param  array<int, mixed>  $curl
     */
    private function send(Request $request, array $curl = [], ?EgressPolicy $policy = null): void
    {
        $middleware = new EgressGuardMiddleware($this->resolver, new AddressClassifier(), $policy ?? new EgressPolicy());

        $handler = function ($request, array $options): string {
            $this->forwardedOptions = $options;

            return 'forwarded';
        };

        $options = [] === $curl ? [] : ['curl' => $curl];

        $this->assertSame('forwarded', $middleware($handler)($request, $options));
    }
}
