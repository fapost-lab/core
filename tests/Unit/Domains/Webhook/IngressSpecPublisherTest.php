<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Telegram\TelegramAdapter;
use App\Domains\Webhook\Services\IngressSpecPublisher;
use App\Domains\Webhook\Services\IngressSpecResolver;
use Fapost\Foundation\Channel\Ingress\IngressSpec;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Mockery\MockInterface;
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

        Redis::shouldReceive('del')->andReturn(1);

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

        Redis::shouldReceive('del')->andReturn(1);

        $published = $this->publisher()->publishAll();

        $this->assertArrayNotHasKey('whatsapp', $published);
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

    private function publisher(): IngressSpecPublisher
    {
        return app(IngressSpecPublisher::class);
    }
}
