<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Settings;

use Spatie\LaravelSettings\Settings;

final class PlatformSettings extends Settings
{
    public string $version;

    public bool $maintenance_mode;

    public string $default_locale;

    public string $default_timezone;

    public static function group(): string
    {
        return 'platform';
    }
}
