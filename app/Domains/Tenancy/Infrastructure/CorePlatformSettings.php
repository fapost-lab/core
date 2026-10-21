<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Infrastructure;

use App\Domains\Tenancy\Services\PlatformSettings;
use Fapost\Foundation\Settings\Contracts\PlatformSettingsInterface;

/**
 * Core's side of Foundation's {@see PlatformSettingsInterface}: an operator package shows and
 * changes Core's platform-wide settings through it, never through Core's tables or config.
 *
 * Built per resolve over the scoped {@see PlatformSettings}, so a caller reads one request's values.
 */
final readonly class CorePlatformSettings implements PlatformSettingsInterface
{
    public function __construct(private PlatformSettings $settings)
    {
    }

    public function definitions(): array
    {
        return $this->settings->definitions();
    }

    public function values(): array
    {
        return $this->settings->values();
    }

    public function update(array $changes, string $actor): void
    {
        $this->settings->update($changes, $actor);
    }
}
