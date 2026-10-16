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
use App\Domains\Media\Registries\ChannelMediaLinkPolicyRegistry;
use App\Domains\Media\Registries\ChannelMediaUploaderRegistry;
use App\Domains\Media\Repositories\EloquentMediaBlobRepository;
use App\Domains\Media\Repositories\EloquentMediaChannelRefRepository;
use App\Domains\Media\Services\ChannelLimitInspector;
use App\Domains\Media\Services\MediaDispatcher;
use App\Domains\Media\Services\MediaFolderService;
use App\Domains\Media\Services\MediaIngestor;
use App\Domains\Media\Services\MediaReferenceTracker;
use App\Domains\Media\Services\MediaService;
use App\Domains\Media\Services\MediaStorageGate;
use App\Domains\Media\Services\MediaUploader;
use App\Domains\Media\Services\PublicMediaLinkIssuer;
use App\Domains\Media\Services\PublicMediaLinkResolver;
use App\Domains\Media\Services\PublicMediaLinkSigner;
use App\Domains\Media\Services\StoredMediaBytes;
use App\Domains\Media\Storage\StoragePathFactory;
use App\Domains\Media\Storage\TenantMediaDisk;
use App\Domains\Tenancy\Services\TenantUsageCounters;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Wires Media Domain bindings, the channel adapter registries, and the listener that
 * keeps media_file_references in sync with FlowDefinition saves.
 *
 * The three channel registries are scoped, not singletons: they hold the services built from the connector tags, and a
 * service that injects a scoped binding would stay pinned to the first job's tenant for the whole worker process.
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
        $this->registerStorageLimit();
        $this->registerPublicLinkRateLimit();
    }

    public function register(): void
    {
        $this->app->singleton(StoragePathFactory::class);
        $this->app->singleton(TenantMediaDisk::class);

        $this->app->scoped(
            ChannelMediaUploaderRegistry::class,
            static fn (Container $app): ChannelMediaUploaderRegistry => new ChannelMediaUploaderRegistry(
                $app->tagged('media.channel.uploader'),
            ),
        );

        $this->app->scoped(
            ChannelMediaDownloaderRegistry::class,
            static fn (Container $app): ChannelMediaDownloaderRegistry => new ChannelMediaDownloaderRegistry(
                $app->tagged('media.channel.downloader'),
            ),
        );

        $this->app->scoped(
            ChannelMediaLinkPolicyRegistry::class,
            static fn (Container $app): ChannelMediaLinkPolicyRegistry => new ChannelMediaLinkPolicyRegistry(
                $app->tagged('media.channel.link'),
            ),
        );

        // Keys are read when resolved, not captured at boot, and nothing here is static (worker-safety).
        $this->app->bind(
            PublicMediaLinkSigner::class,
            static fn (): PublicMediaLinkSigner => new PublicMediaLinkSigner(
                (string)config('app.key'),
                array_values(array_map('strval', (array)config('app.previous_keys', []))),
            ),
        );
        $this->app->scoped(PublicMediaLinkIssuer::class);
        $this->app->bind(PublicMediaLinkResolver::class);

        $this->app->singleton(MediaBlobRepositoryInterface::class, EloquentMediaBlobRepository::class);
        $this->app->singleton(MediaChannelRefRepositoryInterface::class, EloquentMediaChannelRefRepository::class);

        $this->app->singleton(MediaReferenceExtractorInterface::class, FlowDefinitionMediaReferenceExtractor::class);
        $this->app->singleton(MediaReferenceTrackerInterface::class, MediaReferenceTracker::class);

        // Not singletons: both read the scoped tenant context.
        $this->app->bind(StoredMediaBytes::class);
        $this->app->bind(MediaStorageGate::class);

        $this->app->scoped(MediaUploaderInterface::class, MediaUploader::class);
        $this->app->scoped(MediaDispatcherInterface::class, MediaDispatcher::class);
        $this->app->scoped(MediaIngestorInterface::class, MediaIngestor::class);
        $this->app->scoped(MediaServiceInterface::class, MediaService::class);
        $this->app->when(MediaService::class)
            ->needs('$downloadUrlTtlSeconds')
            ->giveConfig('media.download_url_ttl_seconds', 300);
        $this->app->when(MediaFolderService::class)
            ->needs('$maxDepth')
            ->giveConfig('media.folder.max_depth', 10);

        $this->app->singleton(
            ChannelLimitInspector::class,
            static fn (): ChannelLimitInspector => new ChannelLimitInspector(
                (array)config('media.channel_limits', []),
            ),
        );

        $this->app->singleton(MediaPreviewRegistry::class);
    }

    /**
     * One link, one budget: a provider fetching a file asks for it once or twice, so the key is the link itself.
     * A guessed signature never repeats, which is why a scan is not what this limits; it caps a leaked link that is
     * hammered while it is still valid.
     */
    private function registerPublicLinkRateLimit(): void
    {
        RateLimiter::for('media-public-link', static fn (Request $request): Limit => Limit::perMinute(
            (int)config('media.public_links.per_minute', 60),
        )->by(implode('|', [
            (string)$request->route('file'),
            (string)$request->route('expires'),
            (string)$request->route('signature'),
        ])));
    }

    /**
     * The stored-bytes limit and the counter that reports it, registered the way a Solution
     * registers its keys: from boot(), before the registry is frozen.
     */
    private function registerStorageLimit(): void
    {
        $this->app->make(LimitRegistryInterface::class)->register(new LimitDefinition(
            key: MediaStorageGate::LIMIT_KEY,
            label: 'Media storage',
            unit: 'bytes',
            kind: LimitKind::Bytes,
            description: 'Stored media bytes, each unique file counted once; files in the trash count until deleted permanently. An upload over the limit is not stored.',
        ));

        $this->app->make(TenantUsageCounters::class)->register(
            MediaStorageGate::LIMIT_KEY,
            static fn (): int => app(StoredMediaBytes::class)->current(),
        );
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
                iconHeroicon: 'photo',
            )
        );

        $registry->register(
            new MediaPreviewType(
                extensions: ['mp4', 'webm', 'mov'],
                kind: 'video',
                iconHeroicon: 'video-camera',
            )
        );

        $registry->register(
            new MediaPreviewType(
                extensions: ['mp3', 'ogg', 'wav', 'm4a'],
                kind: 'audio',
                iconHeroicon: 'speaker-wave',
            )
        );

        $registry->register(
            new MediaPreviewType(
                extensions: ['pdf'],
                kind: 'document',
                iconHeroicon: 'document-text',
                requiresExternalRender: true,
            )
        );
    }
}
