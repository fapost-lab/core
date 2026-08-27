<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\WebhookRegistryWriterInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Domains\Tenancy\ValueObjects\WebhookRegistryHealthReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Compares the Redis webhook routing cache against landlord.webhook_registry
 * (source of truth) and optionally repairs the drift. Lives in the Tenancy
 * domain because it is the only domain allowed direct landlord access.
 *
 * Failure modes detected: missing (DB row without Redis key), stale (payload
 * mismatch) and orphaned (Redis key without DB row). Repair re-writes missing
 * and stale entries through {@see WebhookRegistryWriterInterface} and deletes
 * orphaned Redis keys.
 */
final readonly class WebhookRegistryHealthChecker
{
    private const string KEY_PREFIX = 'webhook:';

    public function __construct(
        private WebhookRegistryWriterInterface $writer,
    ) {
    }

    /**
     * Build the Redis↔DB diff without mutating anything.
     */
    public function check(): WebhookRegistryHealthReport
    {
        $rows = DB::connection('landlord')->table('webhook_registry')->get();

        $missing  = [];
        $stale    = [];
        $dbHashes = [];

        foreach ($rows as $row) {
            $hash            = (string)$row->webhook_public_hash;
            $dbHashes[$hash] = true;

            $raw = Redis::get(self::KEY_PREFIX . $hash);

            if (null === $raw || false === $raw) {
                $missing[] = $hash;

                continue;
            }

            $payload = json_decode((string)$raw, true);

            if (! is_array($payload) || ! $this->matches($row, $payload)) {
                $stale[] = $hash;
            }
        }

        $orphaned = [];

        foreach ((array)Redis::keys(self::KEY_PREFIX . '*') as $key) {
            $hash = $this->hashFromKey((string)$key);

            if ('' !== $hash && ! isset($dbHashes[$hash])) {
                $orphaned[] = $hash;
            }
        }

        return new WebhookRegistryHealthReport($missing, $stale, $orphaned);
    }

    /**
     * Restore Redis to match the landlord DB: re-write missing/stale entries
     * from their DB rows, delete orphaned keys. Safe to run repeatedly.
     */
    public function repair(WebhookRegistryHealthReport $report): void
    {
        $toRewrite = array_merge($report->missing, $report->stale);

        if ([] !== $toRewrite) {
            $rows = DB::connection('landlord')
                ->table('webhook_registry')
                ->whereIn('webhook_public_hash', $toRewrite)
                ->get();

            foreach ($rows as $row) {
                $this->writer->write(
                    publicHash: (string)$row->webhook_public_hash,
                    tenant: new RuntimeTenant(
                        id: (string)$row->tenant_id,
                        schemaName: (string)$row->schema,
                    ),
                    assistantId: (string)$row->assistant_id,
                    channelId: (string)$row->channel_id,
                    channelType: (string)$row->platform,
                    secretToken: (string)$row->secret_token,
                );
            }
        }

        foreach ($report->orphaned as $hash) {
            Redis::del(self::KEY_PREFIX . $hash);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function matches(object $row, array $payload): bool
    {
        return ($payload['tenant_id'] ?? null) === (string)$row->tenant_id
            && ($payload['assistant_id'] ?? null) === (string)$row->assistant_id
            && ($payload['channel_id'] ?? null) === (string)$row->channel_id
            && ($payload['schema'] ?? null) === (string)$row->schema
            && ($payload['channel'] ?? null) === (string)$row->platform
            && ($payload['secret_token'] ?? null) === (string)$row->secret_token;
    }

    /**
     * Extract the public hash from a raw Redis key. Keys returned by KEYS carry
     * the connection-level prefix, so locate the registry prefix instead of
     * assuming the key starts with it.
     */
    private function hashFromKey(string $key): string
    {
        $position = mb_strpos($key, self::KEY_PREFIX);

        if (false === $position) {
            return '';
        }

        return mb_substr($key, $position + mb_strlen(self::KEY_PREFIX));
    }
}
