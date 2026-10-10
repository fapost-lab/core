<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Media\Exceptions\StorageLimitReachedException;
use Illuminate\Support\Number;

/**
 * The sentence a person sees when an upload is refused for the storage limit.
 */
final class StorageLimitMessage
{
    public static function for(StorageLimitReachedException $exception): string
    {
        return __('media.errors.storage_limit_reached', [
            'used'   => Number::fileSize($exception->used, precision: 1),
            'limit'  => Number::fileSize($exception->limit, precision: 1),
            'needed' => Number::fileSize($exception->incoming, precision: 1),
        ]);
    }
}
