<?php

declare(strict_types=1);

namespace App\Domains\Staff\Support;

use App\Domains\Tenancy\Services\PlatformSettingRegistry;
use App\Domains\Tenancy\ValueObjects\PlatformSettingField;
use Fapost\Foundation\Settings\Enums\PlatformSettingType;

/**
 * The platform settings of tenant API tokens (`adr-20261119-tenant-api-tokens` §9, §10), one place for their keys,
 * bounds and defaults. Defaults come from `config/api_tokens.php`; a stored value wins.
 */
final class ApiTokenSettings
{
    public const string DEFAULT_TTL_DAYS = 'api_tokens.default_ttl_days';

    public const string MAX_TTL_DAYS = 'api_tokens.max_ttl_days';

    public const string ROTATION_GRACE_HOURS = 'api_tokens.rotation_grace_hours';

    public const string PER_MINUTE = 'api_tokens.per_minute';

    /** No token lives longer than this, whatever the setting says. */
    public const int CEILING_DAYS = 365;

    public const int MAX_GRACE_HOURS = 168;

    public static function register(PlatformSettingRegistry $registry): void
    {
        $registry->register(new PlatformSettingField(
            key: self::DEFAULT_TTL_DAYS,
            type: PlatformSettingType::Integer,
            configKey: 'api_tokens.default_ttl_days',
            fallback: 90,
            label: 'API token default lifetime',
            description: 'How many days a new tenant API token lives unless its issuer picks another expiry. Never more than the maximum lifetime.',
            group: 'API tokens',
            unit: 'days',
            min: 1,
            max: self::CEILING_DAYS,
        ));

        $registry->register(new PlatformSettingField(
            key: self::MAX_TTL_DAYS,
            type: PlatformSettingType::Integer,
            configKey: 'api_tokens.max_ttl_days',
            fallback: self::CEILING_DAYS,
            label: 'API token maximum lifetime',
            description: 'The longest expiry an issuer may give a tenant API token, at most 365 days. Every token has an expiry.',
            group: 'API tokens',
            unit: 'days',
            min: 1,
            max: self::CEILING_DAYS,
        ));

        $registry->register(new PlatformSettingField(
            key: self::ROTATION_GRACE_HOURS,
            type: PlatformSettingType::Integer,
            configKey: 'api_tokens.rotation_grace_hours',
            fallback: 24,
            label: 'API token rotation grace',
            description: 'How many hours a rotated tenant API token keeps working next to its successor, so integrations can switch. 0 ends it at once.',
            group: 'API tokens',
            unit: 'hours',
            min: 0,
            max: self::MAX_GRACE_HOURS,
        ));

        $registry->register(new PlatformSettingField(
            key: self::PER_MINUTE,
            type: PlatformSettingType::Integer,
            configKey: 'api_tokens.per_minute',
            fallback: 60,
            label: 'API token requests per minute',
            description: 'How many requests one tenant API token may make in a minute; more are answered 429.',
            group: 'API tokens',
            unit: 'requests',
            min: 1,
            max: 10000,
        ));
    }
}
