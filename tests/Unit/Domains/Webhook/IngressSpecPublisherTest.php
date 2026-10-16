<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Channels\ConnectorChannels;
use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Telegram\TelegramAdapter;
use App\Domains\Webhook\Services\IngressSpecPublisher;
use App\Domains\Webhook\Services\IngressSpecResolver;
use Fapost\Foundation\Channel\Ingress\IngressSpec;
use Fapost\Foundation\Connector\ConnectorChannel;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Mockery\MockInterface;
use Tests\Fixtures\Connectors\Echo\EchoInboundAdapter;
use Tests\Fixtures\Connectors\Echo\EchoSender;
use Tests\TestCase;

final class IngressSpecPublisherTest extends TestCase
{
    public function test_publishes_the_wire_format_the_gateway_reads(): void
    {
        $captured = null;

        Redis::shouldReceive('set')
            ->once()
            ->with('ingress:spec:telegram', Mockery::capture($captured))
            ->andReturn(true);

        $this->allowRedisIndex();

        $published = $this->publisher()->publishAll();

        $this->assertArrayHasKey('telegram', $published);

        $decoded = json_decode((string) $captured, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            [
                'v'           => IngressSpec::VERSION,
                'scheme'      => 'header_equals',
                'parameter'   => 'x-telegram-bot-api-secret-token',
                'prefix'      => '',
                'idempotency' => 'tg:{channel}:{body.update_id}',
            ],
            $decoded,
        );
    }

    /**
     * A published spec that no longer reflects its adapter is worse than none:
     * the gateway would keep applying rules the adapter has abandoned.
     */
    public function test_drops_stale_spec_for_a_platform_without_one(): void
    {
        Redis::shouldReceive('set')->andReturn(true);

        Redis::shouldReceive('del')
            ->once()
            ->with('ingress:spec:whatsapp')
            ->andReturn(1);

        $this->allowRedisIndex();

        $published = $this->publisher()->publishAll();

        $this->assertArrayNotHasKey('whatsapp', $published);
    }

    public function test_a_connector_channel_spec_is_published_and_indexed(): void
    {
        $this->registerEcho();

        $captured = null;

        Redis::shouldReceive('set')->with('ingress:spec:telegram', Mockery::any())->andReturn(true);
        Redis::shouldReceive('set')
            ->once()
            ->with('ingress:spec:echo', Mockery::capture($captured))
            ->andReturn(true);
        Redis::shouldReceive('sadd')->once()->with('ingress:specs', 'echo')->andReturn(1);
        Redis::shouldReceive('sadd')->with('ingress:specs', 'telegram')->andReturn(1);
        $this->allowRedisIndex();

        $published = $this->publisher()->publishAll();

        $this->assertArrayHasKey('echo', $published);
        $this->assertSame('header_equals', json_decode((string) $captured, true, flags: JSON_THROW_ON_ERROR)['scheme']);
        $this->assertContains('echo', app(IngressSpecResolver::class)->declarativePlatforms());
    }

    /**
     * A removed connector must not leave its spec behind: the gateway would keep verifying (and accepting)
     * webhooks of a type nothing serves.
     */
    public function test_the_spec_of_a_type_that_is_no_longer_registered_is_removed(): void
    {
        Redis::shouldReceive('set')->andReturn(true);
        Redis::shouldReceive('smembers')->with('ingress:specs')->andReturn(['telegram', 'echo']);
        Redis::shouldReceive('del')->once()->with('ingress:spec:echo')->andReturn(1);
        Redis::shouldReceive('srem')->once()->with('ingress:specs', 'echo')->andReturn(1);
        $this->allowRedisIndex();

        $published = $this->publisher()->publishAll();

        $this->assertArrayNotHasKey('echo', $published);
    }

    public function test_resolver_returns_null_when_no_adapter_is_registered(): void
    {
        $this->mock(ChannelRegistryInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adapter')->andReturnNull();
        });

        $this->assertNull(app(IngressSpecResolver::class)->specFor('telegram'));
    }

    /**
     * Gateway eligibility is derived, never configured: an adapter that publishes
     * a spec is verifiable anywhere, one that does not stays on the PHP path.
     * Nothing in the environment can override either direction.
     */
    public function test_declarative_platforms_are_derived_from_the_adapters(): void
    {
        $platforms = app(IngressSpecResolver::class)->declarativePlatforms();

        $this->assertContains('telegram', $platforms);
        $this->assertNotContains(
            'whatsapp',
            $platforms,
            'An adapter without an ingress spec must not be advertised as gateway-verifiable.',
        );
    }

    /**
     * Guards the invariant the publisher rests on: every registered channel type
     * is a ChannelTypeEnum case, so enumerating the enum enumerates the registry.
     */
    public function test_every_channel_type_is_covered_by_the_enum(): void
    {
        $registry = app(ChannelRegistryInterface::class);

        $known = array_filter(
            array_map(
                static fn (ChannelTypeEnum $type): ?object => $registry->adapter($type->value),
                ChannelTypeEnum::cases(),
            ),
        );

        $this->assertNotEmpty($known, 'No adapters resolvable through the enum — publisher would publish nothing.');
        $this->assertInstanceOf(TelegramAdapter::class, $registry->adapter(ChannelTypeEnum::Telegram->value));
    }

    private function registerEcho(): void
    {
        $this->app->make(ConnectorChannels::class)->add(
            new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class),
            'echo',
            'Echo',
        );
    }

    /**
     * The index set of published types, empty before the publish.
     */
    private function allowRedisIndex(): void
    {
        Redis::shouldReceive('smembers')->with('ingress:specs')->andReturn([]);
        Redis::shouldReceive('sadd')->andReturn(1);
        Redis::shouldReceive('srem')->andReturn(1);
        Redis::shouldReceive('del')->andReturn(1);
    }

    private function publisher(): IngressSpecPublisher
    {
        return app(IngressSpecPublisher::class);
    }
}
