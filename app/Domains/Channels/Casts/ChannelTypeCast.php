<?php

declare(strict_types=1);

namespace App\Domains\Channels\Casts;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast for a channel type and a contact platform: an open string key owned by an integration.
 *
 * Reading always gives the stored string and never throws, so a row whose integration is gone (a removed connector)
 * still hydrates. Writing accepts a {@see BackedEnum} too, so a built-in `ChannelTypeEnum` or `PlatformEnum` case
 * can be assigned as before.
 *
 * @implements CastsAttributes<string, string|BackedEnum>
 */
final class ChannelTypeCast implements CastsAttributes
{
    /**
     * Without it Eloquent keeps the object that was assigned (an enum case) and gives it back on the next read, in
     * place of the string this cast reads.
     */
    public bool $withoutObjectCaching = true;

    /**
     * {@inheritDoc}
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): string
    {
        return (string) $value;
    }

    /**
     * {@inheritDoc}
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }
}
