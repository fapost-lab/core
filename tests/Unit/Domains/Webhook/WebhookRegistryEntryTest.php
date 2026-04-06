<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\DTOs\WebhookRegistryEntry;
use Tests\TestCase;

final class WebhookRegistryEntryTest extends TestCase
{
    public function test_from_landlord_builds_entry_from_row(): void
    {
        $row = (object) [
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assistant-1',
            'channel_id'   => 'channel-1',
            'schema'       => 'main',
            'platform'     => 'telegram',
            'secret_token' => 'secret',
        ];

        $entry = WebhookRegistryEntry::fromLandlord($row);

        $this->assertSame('tenant-1', $entry->tenantId);
        $this->assertSame('assistant-1', $entry->assistantId);
        $this->assertSame('channel-1', $entry->channelId);
        $this->assertSame('main', $entry->schema);
        $this->assertSame(PlatformEnum::Telegram, $entry->platform);
        $this->assertSame('secret', $entry->secretToken);
    }

    public function test_from_redis_parses_assistant_and_schema_fields(): void
    {
        $entry = WebhookRegistryEntry::fromRedis(json_encode([
            'tenant_id'    => 'tenant-1',
            'assistant_id' => 'assistant-1',
            'channel_id'   => 'channel-1',
            'schema'       => 'main',
            'channel'      => 'telegram',
            'secret_token' => 'secret',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('tenant-1', $entry->tenantId);
        $this->assertSame('assistant-1', $entry->assistantId);
        $this->assertSame('channel-1', $entry->channelId);
        $this->assertSame('main', $entry->schema);
        $this->assertSame(PlatformEnum::Telegram, $entry->platform);
        $this->assertSame('secret', $entry->secretToken);
    }
}
