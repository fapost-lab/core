<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Exceptions\StorageLimitReachedException;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Services\ByteQuota;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Decides whether new content fits into the tenant's `media_storage` limit.
 *
 * The uploader asks once per new blob, after hashing and before any byte is written to the
 * tenant's disk; content the tenant already stores adds no bytes and never reaches the gate.
 * The stored total is counted only when a limit is set, so a tenant without one pays no query.
 *
 * Staff paths (`upload`, `api`) fail closed when the operator errors; inbound paths
 * (`input_node`, `conversation`) fail open, because nobody is there to retry and a broken
 * operator must not lose the files of every bot's clients.
 */
final readonly class MediaStorageGate
{
    public const string LIMIT_KEY = 'media_storage';

    public function __construct(
        private ByteQuota $quota,
        private StoredMediaBytes $stored,
        private TenantContextInterface $context,
    ) {
    }

    /**
     * @param  int  $incoming  bytes of content the tenant does not store yet
     *
     * @throws StorageLimitReachedException when the content does not fit
     * @throws LogicException when the limit key is not registered as a byte limit
     */
    public function assertFits(int $incoming, MediaSource $source): void
    {
        $limit = $this->quota->limit(self::LIMIT_KEY, failOpen: $this->failsOpen($source));

        if (null === $limit) {
            return;
        }

        $used = $this->stored->current();

        if ($used + $incoming <= $limit) {
            return;
        }

        Log::info('quota.storage.refused', [
            'tenant_id' => $this->context->get()->getId(),
            'key'       => self::LIMIT_KEY,
            'limit'     => $limit,
            'used'      => $used,
            'incoming'  => $incoming,
            'source'    => $source->value,
        ]);

        throw new StorageLimitReachedException(self::LIMIT_KEY, $limit, $used, $incoming);
    }

    private function failsOpen(MediaSource $source): bool
    {
        return MediaSource::InputNode === $source || MediaSource::Conversation === $source;
    }
}
