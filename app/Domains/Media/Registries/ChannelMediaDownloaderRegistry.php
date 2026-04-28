<?php

declare(strict_types=1);

namespace App\Domains\Media\Registries;

use App\Domains\Media\Exceptions\ChannelMediaUploaderNotRegisteredException;
use FAPost\Foundation\Media\ChannelMediaDownloaderInterface;

/**
 * Resolves channel-specific media downloaders by channel type.
 *
 * Used by the ingestor when an input node receives a user-supplied attachment that
 * needs to be pulled from the provider before persisting to tenant storage.
 */
final class ChannelMediaDownloaderRegistry
{
    /** @var array<string, ChannelMediaDownloaderInterface> */
    private array $downloaders = [];

    /**
     * @param  iterable<ChannelMediaDownloaderInterface>  $downloaders
     */
    public function __construct(iterable $downloaders = [])
    {
        foreach ($downloaders as $downloader) {
            $this->register($downloader);
        }
    }

    public function register(ChannelMediaDownloaderInterface $downloader): void
    {
        $this->downloaders[$downloader->channelType()] = $downloader;
    }

    public function forChannelType(string $channelType): ChannelMediaDownloaderInterface
    {
        if ( ! isset($this->downloaders[$channelType])) {
            throw ChannelMediaUploaderNotRegisteredException::downloaderForType($channelType);
        }

        return $this->downloaders[$channelType];
    }

    public function has(string $channelType): bool
    {
        return isset($this->downloaders[$channelType]);
    }
}
