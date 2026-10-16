<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

use LogicException;

/**
 * What another domain reads of the platform-wide settings: the value in effect, the stored one or
 * else the default. Read once per request or job; a value stored meanwhile is seen by the next one.
 *
 * Bound scoped: a consumer that outlives a job (a singleton) must not hold it.
 */
interface PlatformSettingsReaderInterface
{
    /**
     * @throws LogicException when the key is not a registered integer setting
     */
    public function integer(string $key): int;

    /**
     * @throws LogicException when the key is not a registered boolean setting
     */
    public function boolean(string $key): bool;

    /**
     * @throws LogicException when the key is not a registered text or choice setting
     */
    public function text(string $key): string;
}
