<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Behind Caddy or a load balancer the socket peer is the proxy; the client address is believed
 * from X-Forwarded-For only when the peer is a configured trusted proxy (`TRUSTED_PROXIES`).
 */
final class TrustedProxiesTest extends TestCase
{
    private const string CLIENT = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_probe/request', fn (Request $request): array => [
            'ip'     => $request->ip(),
            'secure' => $request->secure(),
            'host'   => $request->getHost(),
            'port'   => $request->getPort(),
        ]);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    public function test_no_configured_proxy_means_the_socket_peer_is_the_client(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->probe('172.18.0.5')->assertJsonPath('ip', '172.18.0.5');
    }

    public function test_forwarded_address_is_believed_from_a_trusted_proxy_range(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.0/8,172.16.0.0/12']);

        $this->probe('172.18.0.5')->assertJsonPath('ip', self::CLIENT);
    }

    public function test_forwarded_address_is_ignored_from_a_peer_outside_the_trusted_ranges(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.0/8,172.16.0.0/12']);

        $this->probe('198.51.100.7')->assertJsonPath('ip', '198.51.100.7');
    }

    public function test_the_hops_of_a_trusted_chain_are_skipped_to_reach_the_client(): void
    {
        config(['trustedproxy.proxies' => '172.16.0.0/12']);

        // Caddy is the peer (nginx passes $remote_addr on as REMOTE_ADDR) and appended itself to the header.
        $this->probe('172.18.0.5', self::CLIENT . ', 172.18.0.2')->assertJsonPath('ip', self::CLIENT);
    }

    public function test_an_address_forged_by_the_caller_is_not_taken_over_a_real_one_the_proxy_appended(): void
    {
        config(['trustedproxy.proxies' => '172.16.0.0/12']);

        // The caller sent 1.2.3.4; the trusted proxy appended the address it really saw.
        $this->probe('172.18.0.5', '1.2.3.4, ' . self::CLIENT)->assertJsonPath('ip', self::CLIENT);
    }

    public function test_a_star_trusts_whoever_connects_directly(): void
    {
        config(['trustedproxy.proxies' => '*']);

        $this->probe('198.51.100.7')->assertJsonPath('ip', self::CLIENT);
    }

    public function test_scheme_follows_a_trusted_proxy_but_the_host_and_the_port_do_not(): void
    {
        config(['trustedproxy.proxies' => '172.16.0.0/12']);

        $this->probe('172.18.0.5', self::CLIENT, [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port'  => '9999',
            'X-Forwarded-Host'  => 'evil.example',
        ])
            ->assertJsonPath('secure', true)
            // The port comes from the Host header (APP_URL's port, else 443 for https), never from X-Forwarded-Port.
            ->assertJsonPath('port', parse_url((string) config('app.url'), PHP_URL_PORT) ?? 443)
            ->assertJsonPath('host', parse_url((string) config('app.url'), PHP_URL_HOST));
    }

    public function test_the_environment_variable_feeds_the_config(): void
    {
        foreach (['', '  '] as $empty) {
            putenv("TRUSTED_PROXIES={$empty}");
            $this->assertNull((require base_path('config/trustedproxy.php'))['proxies']);
        }

        putenv('TRUSTED_PROXIES= 10.0.0.0/8,172.16.0.0/12 ');
        $this->assertSame('10.0.0.0/8,172.16.0.0/12', (require base_path('config/trustedproxy.php'))['proxies']);

        putenv('TRUSTED_PROXIES');
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function probe(string $peer, string $forwardedFor = self::CLIENT, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this
            ->withServerVariables(['REMOTE_ADDR' => $peer])
            ->withHeaders(['X-Forwarded-For' => $forwardedFor, ...$headers])
            ->getJson('/_probe/request')
            ->assertOk();
    }
}
