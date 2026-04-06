<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\Adapters\TelegramChannelAdapter;
use App\Domains\Webhook\Exceptions\AdapterNotFoundException;
use App\Domains\Webhook\Services\ChannelAdapterResolver;
use Illuminate\Contracts\Container\Container;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class ChannelAdapterResolverTest extends TestCase
{
    public function test_resolve_uses_container_make_for_registered_platform(): void
    {
        /** @var Container&MockInterface $container */
        $container = Mockery::mock(Container::class);
        $adapter   = new TelegramChannelAdapter();

        $container
            ->shouldReceive('make')
            ->once()
            ->with(TelegramChannelAdapter::class)
            ->andReturn($adapter);

        $resolver = new ChannelAdapterResolver(
            container: $container,
            adapterMap: ['telegram' => TelegramChannelAdapter::class],
        );

        $resolved = $resolver->resolve(PlatformEnum::Telegram);

        $this->assertSame($adapter, $resolved);
    }

    public function test_resolve_throws_for_unregistered_platform(): void
    {
        /** @var Container&MockInterface $container */
        $container = Mockery::mock(Container::class);
        $resolver  = new ChannelAdapterResolver(
            container: $container,
            adapterMap: ['telegram' => TelegramChannelAdapter::class],
        );

        $this->expectException(AdapterNotFoundException::class);

        $resolver->resolve(PlatformEnum::WhatsApp);
    }
}
