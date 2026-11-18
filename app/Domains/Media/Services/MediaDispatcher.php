<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Channels\Models\Channel;
use App\Domains\Media\Contracts\MediaChannelRefRepositoryInterface;
use App\Domains\Media\Contracts\MediaDispatcherInterface;
use App\Domains\Media\DTO\DispatchResult;
use App\Domains\Media\Exceptions\MediaDeletedException;
use App\Domains\Media\Models\MediaChannelRef;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Registries\ChannelMediaLinkPolicyRegistry;
use App\Domains\Media\Registries\ChannelMediaUploaderRegistry;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Media\DTO\UploadContext;
use Fapost\Foundation\Media\ReportsProviderKind;

/**
 * Resolves provider-side file ids for sending media through a channel.
 *
 * Lazy upload + per-channel cache: first send uploads and stores the result in
 * media_channel_refs; subsequent sends short-circuit to the cached provider_file_id
 * unless `expires_at` has lapsed (relevant for WhatsApp's 30-day TTL). A ref without a stored kind is treated
 * as lapsed too for an uploader that reports one, see {@see self::predatesProviderKind()}.
 *
 * For upload-as-send providers (Telegram), the cache-miss upload also delivers the
 * payload to the recipient — surfaced via {@see DispatchResult::$alreadyDelivered}
 * so the calling handler does not double-send.
 *
 * A channel type that has no uploader but declares a link policy gets a public link instead: nothing is uploaded,
 * nothing is cached in media_channel_refs (a link is minted per send), and the result carries `publicLink`. An
 * uploader wins over a policy, so an existing channel never changes how it is served.
 */
final readonly class MediaDispatcher implements MediaDispatcherInterface
{
    public function __construct(
        private MediaChannelRefRepositoryInterface $refs,
        private ChannelMediaUploaderRegistry $uploaderRegistry,
        private ChannelMediaLinkPolicyRegistry $linkPolicyRegistry,
        private PublicMediaLinkIssuer $linkIssuer,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function ensureUploadedToChannel(
        MediaFile $media,
        Channel $channel,
        ?UploadContext $context = null,
    ): DispatchResult {
        if (null !== $media->deleted_at) {
            throw MediaDeletedException::forId($media->id);
        }

        $channelType = $channel->type;
        $linkPolicy  = $this->uploaderRegistry->has($channelType)
            ? null
            : $this->linkPolicyRegistry->forChannelType($channelType);

        if (null !== $linkPolicy) {
            return new DispatchResult(
                providerFileId: null,
                publicLink: $this->linkIssuer->issue($media, $this->tenantContext->get(), $linkPolicy->linkTtlSeconds()),
            );
        }

        $blob     = $media->blob;
        $existing = $this->refs->find($blob->id, $channel->id);

        if (null !== $existing && ! $this->isExpired($existing) && ! $this->predatesProviderKind($existing, $channelType)) {
            return new DispatchResult(
                providerFileId: $existing->provider_file_id,
                alreadyDelivered: false,
                providerKind: $existing->provider_kind,
            );
        }

        $uploader = $this->uploaderRegistry->forChannelType($channel->type);
        $result   = $uploader->upload($blob, $channel, $context);

        $this->refs->upsert(
            blobId: $blob->id,
            channelId: $channel->id,
            providerFileId: $result->providerFileId,
            expiresAt: $result->expiresAt,
            providerKind: $result->providerKind,
        );

        return new DispatchResult(
            providerFileId: $result->providerFileId,
            alreadyDelivered: null !== $result->deliveredMessageId,
            deliveredMessageId: $result->deliveredMessageId,
            providerKind: $result->providerKind,
        );
    }

    /**
     * A ref minted before `provider_kind` existed does not say how the provider stored the file, and a file id cannot
     * be sent by another method than the one that minted it (a voice id through sendAudio is refused). The migration
     * cannot backfill it, so for an uploader that reports the kind ({@see ReportsProviderKind}) such a ref counts as
     * a cache miss: the re-upload stores the kind, once per ref. Other uploaders never report one.
     */
    private function predatesProviderKind(MediaChannelRef $ref, string $channelType): bool
    {
        return null === $ref->provider_kind
            && $this->uploaderRegistry->has($channelType)
            && $this->uploaderRegistry->forChannelType($channelType) instanceof ReportsProviderKind;
    }

    private function isExpired(MediaChannelRef $ref): bool
    {
        return null !== $ref->expires_at
               && $ref->expires_at->lessThanOrEqualTo(CarbonImmutable::now());
    }
}
