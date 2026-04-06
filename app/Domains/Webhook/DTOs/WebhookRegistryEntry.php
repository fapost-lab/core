<?php

declare(strict_types=1);

namespace App\Domains\Webhook\DTOs;

use App\Domains\Contact\Enums\PlatformEnum;
use JsonException;
use Spatie\LaravelData\Data;
use ValueError;

final class WebhookRegistryEntry extends Data
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $assistantId,
        public readonly string $channelId,
        public readonly string $schema,
        public readonly PlatformEnum $platform,
        public readonly string $secretToken,
    ) {
    }

    /**
     * Build entry from a landlord webhook_registry row (DB fallback path).
     *
     * @throws ValueError When platform value is not a valid PlatformEnum case.
     */
    public static function fromLandlord(object $row): self
    {
        return new self(
            tenantId: $row->tenant_id,
            assistantId: $row->assistant_id,
            channelId: $row->channel_id,
            schema: $row->schema,
            platform: PlatformEnum::from($row->platform),
            secretToken: $row->secret_token,
        );
    }

    /**
     * @throws JsonException
     * @throws ValueError
     */
    public static function fromRedis(string $json): self
    {
        /** @var array{
         *     tenant_id: string,
         *     assistant_id: string,
         *     channel_id: string,
         *     schema: string,
         *     platform?: string,
         *     channel?: string,
         *     secret_token: string
         * } $data
         */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return new self(
            tenantId: $data['tenant_id'],
            assistantId: $data['assistant_id'],
            channelId: $data['channel_id'],
            schema: $data['schema'],
            platform: PlatformEnum::from($data['platform'] ?? $data['channel']),
            secretToken: $data['secret_token'],
        );
    }
}
