<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Settings;

use Spatie\LaravelSettings\Settings;

final class TenantSettings extends Settings
{
    public int $messaging_rate_limit = 30;

    public int $broadcast_chunk_size = 100;

    public bool $broadcast_backpressure = true;

    /**
     * Whether the tenant keeps a transcript; null = the install default (`CONVERSATION_LOGGING`). The settings
     * migration adds it as null, so an existing tenant keeps the install default until someone stores a value.
     */
    public ?bool $conversation_logging = null;

    /** Whether inbound attachments are downloaded into the tenant's media storage; null = the install default (`CONVERSATION_MEDIA_FETCH`). */
    public ?bool $conversation_media_fetch = null;

    public int $flow_session_ttl = 86400;

    public int $max_retry_attempts = 3;

    public string $flow_fallback_message = 'An error occurred. Please try again later.';

    public int $webhook_timeout = 10;

    public int $webhook_rate_limit = 60;

    public string $content_base_language = 'en';

    /**
     * @var list<string>
     */
    public array $available_languages = ['en'];

    public string $fallback_language = 'en';

    public static function group(): string
    {
        return 'tenant';
    }
}
