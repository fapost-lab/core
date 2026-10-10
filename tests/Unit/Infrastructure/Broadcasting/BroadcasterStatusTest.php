<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Broadcasting;

use App\Infrastructure\Broadcasting\BroadcasterStatus;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

/**
 * The browser learns from BroadcasterStatus whether to listen and where: only for a Pusher-protocol driver with an app
 * key, never with the secret, and with the page's own origin unless an endpoint is configured.
 */
final class BroadcasterStatusTest extends TestCase
{
    public function test_nothing_to_listen_to_when_nothing_delivers(): void
    {
        foreach (['null', 'log', ''] as $default) {
            $status = $this->broadcasterStatus(['default' => $default]);

            $this->assertFalse($status->enabled(), $default);
            $this->assertNull($status->client(), $default);
        }
    }

    public function test_reverb_hands_the_browser_its_key_and_the_page_origin_by_default(): void
    {
        $client = $this->broadcasterStatus(['default' => 'reverb'])->client();

        $this->assertSame(
            ['driver' => 'reverb', 'key' => 'reverb-key', 'cluster' => null, 'host' => null, 'port' => null, 'scheme' => null],
            $client,
        );
        $this->assertStringNotContainsString('reverb-secret', (string) json_encode($client));
    }

    public function test_a_configured_endpoint_reaches_the_browser(): void
    {
        $client = $this->broadcasterStatus([
            'default' => 'reverb',
            'client'  => ['host' => ' ws.example.com ', 'port' => '6001', 'scheme' => 'HTTPS'],
        ])->client();

        $this->assertSame('ws.example.com', $client['host'] ?? null);
        $this->assertSame(6001, $client['port'] ?? null);
        $this->assertSame('https', $client['scheme'] ?? null);
    }

    public function test_a_malformed_endpoint_falls_back_to_the_page_origin(): void
    {
        $client = $this->broadcasterStatus([
            'default' => 'reverb',
            'client'  => ['host' => '', 'port' => 'eighty', 'scheme' => 'ws'],
        ])->client();

        $this->assertNull($client['host'] ?? null);
        $this->assertNull($client['port'] ?? null);
        $this->assertNull($client['scheme'] ?? null);
    }

    public function test_pusher_hands_the_browser_its_key_and_cluster(): void
    {
        $client = $this->broadcasterStatus(['default' => 'pusher'])->client();

        $this->assertSame('pusher', $client['driver'] ?? null);
        $this->assertSame('pusher-key', $client['key'] ?? null);
        $this->assertSame('eu', $client['cluster'] ?? null);
        $this->assertStringNotContainsString('pusher-secret', (string) json_encode($client));
    }

    public function test_a_driver_the_browser_cannot_speak_delivers_but_leaves_it_polling(): void
    {
        $status = $this->broadcasterStatus(['default' => 'redis']);

        $this->assertTrue($status->enabled());
        $this->assertNull($status->client());
    }

    public function test_no_app_key_leaves_the_browser_polling(): void
    {
        $status = $this->broadcasterStatus([
            'default'     => 'reverb',
            'connections' => ['reverb' => ['driver' => 'reverb', 'key' => null, 'secret' => 's']],
        ]);

        $this->assertNull($status->client());
    }

    /**
     * @param  array<string, mixed>  $broadcasting
     */
    private function broadcasterStatus(array $broadcasting): BroadcasterStatus
    {
        $defaults = [
            'connections' => [
                'reverb' => ['driver' => 'reverb', 'key' => 'reverb-key', 'secret' => 'reverb-secret', 'app_id' => '1', 'options' => ['host' => 'reverb', 'port' => 8080, 'scheme' => 'http']],
                'pusher' => ['driver' => 'pusher', 'key' => 'pusher-key', 'secret' => 'pusher-secret', 'app_id' => '2', 'options' => ['cluster' => 'eu']],
                'redis'  => ['driver' => 'redis', 'connection' => 'default'],
            ],
            'client' => ['host' => null, 'port' => null, 'scheme' => null],
        ];

        return new BroadcasterStatus(new Repository(['broadcasting' => array_replace($defaults, $broadcasting)]));
    }
}
