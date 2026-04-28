<?php

declare(strict_types=1);

namespace App\Domains\Media\Providers;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Media\Contracts\MediaBlobRepositoryInterface;
use App\Domains\Media\Contracts\MediaChannelRefRepositoryInterface;
use App\Domains\Media\Contracts\MediaDispatcherInterface;
use App\Domains\Media\Contracts\MediaIngestorInterface;
use App\Domains\Media\Contracts\MediaReferenceExtractorInterface;
use App\Domains\Media\Contracts\MediaReferenceTrackerInterface;
use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Extractors\FlowDefinitionMediaReferenceExtractor;
use App\Domains\Media\Listeners\TrackFlowDefinitionMediaReferences;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Media\Policies\MediaFilePolicy;
use App\Domains\Media\Policies\MediaFolderPolicy;
use App\Domains\Media\Preview\MediaPreviewRegistry;
use App\Domains\Media\Preview\MediaPreviewType;
use App\Domains\Media\Registries\ChannelMediaDownloaderRegistry;
use App\Domains\Media\Registries\ChannelMediaUploaderRegistry;
use App\Domains\Media\Repositories\EloquentMediaBlobRepository;
use App\Domains\Media\Repositories\EloquentMediaChannelRefRepository;
use App\Domains\Media\Services\ChannelLimitInspector;
use App\Domains\Media\Services\MediaDispatcher;
use App\Domains\Media\Services\MediaIngestor;
use App\Domains\Media\Services\MediaReferenceTracker;
use App\Domains\Media\Services\MediaService;
use App\Domains\Media\Services\MediaUploader;
use App\Domains\Media\Storage\StoragePathFactory;
use App\Domains\Media\Storage\TenantMediaDisk;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires Media Domain bindings, the channel adapter registries, and the listener that
 * keeps media_file_references in sync with FlowDefinition saves.
 */
final class MediaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        FlowDefinition::saved(static function (FlowDefinition $definition): void {
            app(TrackFlowDefinitionMediaReferences::class)->handle($definition);
        });

        Gate::policy(MediaFile::class, MediaFilePolicy::class);
        Gate::policy(MediaFolder::class, MediaFolderPolicy::class);

        $this->registerBuiltInPreviewTypes();
    }

    public function register(): void
    {
        $this->app->singleton(StoragePathFactory::class);
        $this->app->singleton(TenantMediaDisk::class);

        $this->app->singleton(
            ChannelMediaUploaderRegistry::class,
            static fn (Container $app): ChannelMediaUploaderRegistry => new ChannelMediaUploaderRegistry(
                $app->tagged('media.channel.uploader'),
            ),
        );

        $this->app->singleton(
            ChannelMediaDownloaderRegistry::class,
            static fn (Container $app): ChannelMediaDownloaderRegistry => new ChannelMediaDownloaderRegistry(
                $app->tagged('media.channel.downloader'),
            ),
        );

        $this->app->singleton(MediaBlobRepositoryInterface::class, EloquentMediaBlobRepository::class);
        $this->app->singleton(MediaChannelRefRepositoryInterface::class, EloquentMediaChannelRefRepository::class);

        $this->app->singleton(MediaReferenceExtractorInterface::class, FlowDefinitionMediaReferenceExtractor::class);
        $this->app->singleton(MediaReferenceTrackerInterface::class, MediaReferenceTracker::class);

        $this->app->scoped(MediaUploaderInterface::class, MediaUploader::class);
        $this->app->scoped(MediaDispatcherInterface::class, MediaDispatcher::class);
        $this->app->scoped(MediaIngestorInterface::class, MediaIngestor::class);
        $this->app->scoped(MediaServiceInterface::class, MediaService::class);

        $this->app->singleton(
            ChannelLimitInspector::class,
            static fn (): ChannelLimitInspector => new ChannelLimitInspector(
                (array)config('media.channel_limits', []),
            ),
        );

        $this->app->singleton(MediaPreviewRegistry::class);
    }

    /**
     * Register the platform's built-in preview types. Plugins/Solutions add their own
     * by resolving the registry from their service providers.
     */
    private function registerBuiltInPreviewTypes(): void
    {
        $registry = $this->app->make(MediaPreviewRegistry::class);

        $registry->register(
            new MediaPreviewType(
                extensions: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
                kind: 'image',
                view: 'media.preview.image',
                iconHeroicon: 'photo',
            )
        );

        $registry->register(
            new MediaPreviewType(
                extensions: ['mp4', 'webm', 'mov'],
                kind: 'video',
                view: 'media.preview.video',
                iconHeroicon: 'video-camera',
            )
        );

        $registry->register(
            new MediaPreviewType(
                extensions: ['mp3', 'ogg', 'wav', 'm4a'],
                kind: 'audio',
                view: 'media.preview.audio',
                iconHeroicon: 'speaker-wave',
            )
        );

        $registry->register(
            new MediaPreviewType(
                extensions: ['pdf'],
                kind: 'document',
                view: 'media.preview.pdf',
                iconHeroicon: 'document-text',
                requiresExternalRender: true,
            )
        );
    }
}
