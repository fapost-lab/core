<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Jobs;

use App\Domains\Channels\Models\Channel;
use App\Domains\Conversation\Contracts\ConversationStoreInterface;
use App\Domains\Media\Contracts\MediaIngestorInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Exceptions\StorageLimitReachedException;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Downloads the inbound media of a logged message through the Media domain and
 * rewrites the message's media descriptors with the resolved `media_file_id`
 * (spec §6.1). Runs async on `messaging.logging`: the message text is already in
 * the transcript, so a failed download degrades the descriptor to `failed` while
 * keeping the provider file id — it never blocks the pipeline. A file the tenant has no storage
 * left for degrades the same way, with `reason: storage_limit_reached`.
 */
final class FetchConversationMediaJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array<string, mixed>>  $media
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $messageId,
        public readonly string $channelId,
        public readonly array $media,
    ) {
        $this->onQueue('messaging.logging');
    }

    public function handle(
        MediaIngestorInterface $ingestor,
        ConversationStoreInterface $store,
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
        LoggerInterface $logger,
    ): void {
        $tenant = $tenants->getById($this->tenantId);

        $switcher->runForTenant($tenant, function () use ($ingestor, $store, $logger): void {
            $channel = Channel::query()->find($this->channelId);

            if (null === $channel) {
                $logger->warning('conversation.media.channel_missing', [
                    'channel_id' => $this->channelId,
                    'message_id' => $this->messageId,
                ]);

                return;
            }

            $resolved = [];

            foreach ($this->media as $descriptor) {
                $resolved[] = $this->resolveDescriptor($ingestor, $channel, $descriptor, $logger);
            }

            $store->updateMessageMedia($this->messageId, $resolved);
        });
    }

    /**
     * @param  array<string, mixed>  $descriptor
     * @return array<string, mixed>
     */
    private function resolveDescriptor(
        MediaIngestorInterface $ingestor,
        Channel $channel,
        array $descriptor,
        LoggerInterface $logger,
    ): array {
        $providerFileId = is_string($descriptor['provider_file_id'] ?? null) ? $descriptor['provider_file_id'] : null;

        // Already resolved, or nothing to fetch — leave untouched.
        if (('pending' !== ($descriptor['status'] ?? null)) || null === $providerFileId) {
            return $descriptor;
        }

        try {
            $mediaFile = $ingestor->ingestFromChannel(
                channel: $channel,
                providerFileId: $providerFileId,
                source: MediaSource::Conversation,
            )->loadMissing('blob');

            return [
                'media_file_id'    => (string) $mediaFile->getKey(),
                'kind'             => $mediaFile->kind->value,
                'mime'             => $mediaFile->blob?->mime_type,
                'file_name'        => $mediaFile->name,
                'size'             => $mediaFile->blob?->size,
                'provider_file_id' => $providerFileId,
                'status'           => 'ready',
            ];
        } catch (StorageLimitReachedException) {
            // The gate has logged the refusal. Retrying cannot help until the tenant frees space, so
            // the descriptor degrades to failed with its reason and the job ends normally.
            return [
                'provider_file_id' => $providerFileId,
                'status'           => 'failed',
                'reason'           => StorageLimitReachedException::ERROR_KEY,
            ] + $descriptor;
        } catch (Throwable $exception) {
            $logger->warning('conversation.media.fetch_failed', [
                'channel_id'       => $this->channelId,
                'message_id'       => $this->messageId,
                'provider_file_id' => $providerFileId,
                'error'            => $exception->getMessage(),
            ]);

            return ['provider_file_id' => $providerFileId, 'status' => 'failed'] + $descriptor;
        }
    }
}
