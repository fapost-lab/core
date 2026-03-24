<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Settings;

use Spatie\LaravelSettings\Settings;

final class TenantSettings extends Settings
{
    public int $messaging_rate_limit;

    public int $broadcast_chunk_size;

    public bool $broadcast_backpressure;

    public int $flow_session_ttl;

    public int $max_retry_attempts;

    public string $flow_fallback_message;

    public int $webhook_timeout;

    public int $webhook_rate_limit;

    public int $max_contacts;

    public static function group(): string
    {
        return 'tenant';
    }
}
