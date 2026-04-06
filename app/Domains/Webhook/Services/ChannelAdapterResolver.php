<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use App\Domains\Webhook\Exceptions\AdapterNotFoundException;
use Illuminate\Contracts\Container\Container;

final readonly class ChannelAdapterResolver
{
    /**
     * @param  array<string, class-string<ChannelAdapterInterface>>  $adapterMap
     */
    public function __construct(
        private Container $container,
        private array $adapterMap,
    ) {
    }

    /**
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function resolve(PlatformEnum $platform): ChannelAdapterInterface
    {
        $class = $this->adapterMap[$platform->value] ?? null;

        if (null === $class) {
            throw new AdapterNotFoundException("No adapter registered for platform: {$platform->value}");
        }

        return $this->container->make($class);
    }
}
