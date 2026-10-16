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

/**
 * Resolves provider-side file ids for sending media through a channel.
 *
 * Lazy upload + per-channel cache: first send uploads and stores the result in
 * media_channel_refs; subsequent sends short-circuit to the cached provider_file_id
 * unless `expires_at` has lapsed (relevant for WhatsApp's 30-day TTL).
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

        $channelType = $channel->type->value;
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

        if (null !== $existing && ! $this->isExpired($existing)) {
            return new DispatchResult(
                providerFileId: $existing->provider_file_id,
                alreadyDelivered: false,
            );
        }

        $uploader = $this->uploaderRegistry->forChannelType($channel->type->value);
        $result   = $uploader->upload($blob, $channel, $context);

        $this->refs->upsert(
            blobId: $blob->id,
            channelId: $channel->id,
            providerFileId: $result->providerFileId,
            expiresAt: $result->expiresAt,
        );

        return new DispatchResult(
            providerFileId: $result->providerFileId,
            alreadyDelivered: null !== $result->deliveredMessageId,
            deliveredMessageId: $result->deliveredMessageId,
        );
    }

    private function isExpired(MediaChannelRef $ref): bool
    {
        return null !== $ref->expires_at
               && $ref->expires_at->lessThanOrEqualTo(CarbonImmutable::now());
    }
}
