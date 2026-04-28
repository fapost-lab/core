<?php

declare(strict_types=1);

namespace App\Domains\Media\DTO;

/**
 * Outcome of {@see \App\Domains\Media\Contracts\MediaDispatcherInterface::ensureUploadedToChannel()}.
 *
 * For cache hits and providers with separate upload endpoints, `alreadyDelivered` is
 * false and the caller must perform the actual send using `providerFileId`. For Telegram
 * upload-as-send (cache miss), `alreadyDelivered` is true and the bytes were delivered
 * to the target chat as part of the upload — the caller MUST NOT send the same payload
 * again or the recipient sees a duplicate.
 *
 * `deliveredMessageId` is the platform-side message id of the upload-as-send call, useful
 * for logging or downstream operations (edit/delete).
 */
final readonly class DispatchResult
{
    public function __construct(
        public string $providerFileId,
        public bool $alreadyDelivered = false,
        public ?string $deliveredMessageId = null,
    ) {
    }
}
