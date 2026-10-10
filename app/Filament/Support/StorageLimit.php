<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domains\Media\Exceptions\StorageLimitReachedException;
use App\Domains\Media\Services\StorageLimitMessage;
use Filament\Notifications\Notification;

/**
 * Filament side of an upload refused for the tenant's storage limit: one danger notification
 * that says why and, for a batch, how many files were saved before the refusal.
 */
final class StorageLimit
{
    public static function notifyRefused(StorageLimitReachedException $exception, ?int $saved = null, ?int $total = null): void
    {
        $body = StorageLimitMessage::for($exception);

        if (null !== $saved && null !== $total) {
            $body .= ' ' . __('media.errors.storage_limit_saved', ['saved' => $saved, 'total' => $total]);
        }

        Notification::make()
            ->danger()
            ->title(__('media.errors.storage_limit_title'))
            ->body($body)
            ->send();
    }
}
