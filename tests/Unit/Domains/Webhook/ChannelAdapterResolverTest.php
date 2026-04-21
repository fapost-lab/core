<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\Adapters\TelegramChannelAdapter;
use App\Domains\Webhook\Exceptions\AdapterNotFoundException;
use App\Domains\Webhook\Services\ChannelAdapterResolver;
use Mockery\MockInterface;
use Tests\TestCase;

final class ChannelAdapterResolverTest extends TestCase
{
    public function test_resolve_returns_registered_platform_adapter(): void
    {
        $adapter  = new TelegramChannelAdapter();
        $registry = $this->mock(ChannelRegistryInterface::class, function (MockInterface $mock) use ($adapter): void {
            $mock->shouldReceive('adapter')->once()->with('telegram')->andReturn($adapter);
        });

        $resolver = new ChannelAdapterResolver(
            channelRegistry: $registry,
        );

        $resolved = $resolver->resolve(PlatformEnum::Telegram);

        $this->assertSame($adapter, $resolved);
    }

    public function test_resolve_throws_for_unregistered_platform(): void
    {
        $registry = $this->mock(ChannelRegistryInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('adapter')->once()->with('whatsapp')->andReturn(null);
        });
        $resolver = new ChannelAdapterResolver(
            channelRegistry: $registry,
        );

        $this->expectException(AdapterNotFoundException::class);

        $resolver->resolve(PlatformEnum::WhatsApp);
    }
}
