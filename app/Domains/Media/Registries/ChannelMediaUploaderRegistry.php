<?php

declare(strict_types=1);

namespace App\Domains\Media\Registries;

use App\Domains\Media\Exceptions\ChannelMediaUploaderNotRegisteredException;
use Fapost\Foundation\Media\ChannelMediaUploaderInterface;

/**
 * Resolves channel-specific media uploaders by channel type.
 *
 * Populated at boot from tagged container bindings; queried at runtime by the dispatcher
 * the first time a media file is sent through a channel. Plugin/Solution adapters can
 * register additional uploaders without touching Core.
 */
final class ChannelMediaUploaderRegistry
{
    /** @var array<string, ChannelMediaUploaderInterface> */
    private array $uploaders = [];

    /**
     * @param  iterable<ChannelMediaUploaderInterface>  $uploaders
     */
    public function __construct(iterable $uploaders = [])
    {
        foreach ($uploaders as $uploader) {
            $this->register($uploader);
        }
    }

    public function register(ChannelMediaUploaderInterface $uploader): void
    {
        $this->uploaders[$uploader->channelType()] = $uploader;
    }

    public function forChannelType(string $channelType): ChannelMediaUploaderInterface
    {
        if (! isset($this->uploaders[$channelType])) {
            throw ChannelMediaUploaderNotRegisteredException::forType($channelType);
        }

        return $this->uploaders[$channelType];
    }

    public function has(string $channelType): bool
    {
        return isset($this->uploaders[$channelType]);
    }
}
