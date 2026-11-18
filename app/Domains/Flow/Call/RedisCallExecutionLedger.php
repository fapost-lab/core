<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call;

use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use Fapost\Foundation\Flow\Call\CallResult;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * {@see CallExecutionLedgerInterface} in Redis, keyed `flow:call:{tenant}:{key}:done` and `...:attempts`.
 *
 * Keeps the response of a successful call (never the request, which carries credentials) for the platform
 * setting {@see self::TTL_SETTING}; a result over {@see self::MAX_RECORD_BYTES} is not kept. Every Redis failure
 * is logged and answered as "no record", so the call is sent as it was before the ledger existed.
 *
 * Bound scoped: it reads a platform setting, and holds nothing between jobs.
 */
final readonly class RedisCallExecutionLedger implements CallExecutionLedgerInterface
{
    public const string TTL_SETTING = 'flow.call.dedup_ttl_hours';

    public const int MAX_RECORD_BYTES = 262144;

    public function __construct(
        private RedisFactory $redis,
        private PlatformSettingsReaderInterface $settings,
        private LoggerInterface $logger,
    ) {
    }

    public function completed(string $tenantId, string $key): ?CallResult
    {
        try {
            $raw = $this->redis->connection()->get($this->key($tenantId, $key, 'done'));

            if (! is_string($raw)) {
                return null;
            }

            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $this->warn('read', $tenantId, $exception);

            return null;
        }

        if (! is_array($data)) {
            return null;
        }

        return new CallResult(
            success: true === ($data['success'] ?? null),
            payload: $data['payload'] ?? null,
            errorCode: is_string($data['error_code'] ?? null) ? $data['error_code'] : null,
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }

    public function startAttempt(string $tenantId, string $key): int
    {
        try {
            $attemptsKey = $this->key($tenantId, $key, 'attempts');
            $ttl         = $this->ttlSeconds();

            /** @var array{int, mixed} $results */
            $results = $this->redis->connection()->pipeline(function ($pipe) use ($attemptsKey, $ttl): void {
                $pipe->incr($attemptsKey);
                $pipe->expire($attemptsKey, $ttl);
            });

            return max(1, (int) $results[0]);
        } catch (Throwable $exception) {
            $this->warn('count', $tenantId, $exception);

            return 1;
        }
    }

    public function complete(string $tenantId, string $key, CallResult $result): void
    {
        try {
            $encoded = json_encode([
                'success'    => $result->success,
                'payload'    => $result->payload,
                'error_code' => $result->errorCode,
                'metadata'   => $result->metadata,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->warn('encode', $tenantId, $exception);

            return;
        }

        $roundTrip = json_decode($encoded, true);

        // A payload that JSON does not give back as it was (an object from an action) would replay differently.
        if (! is_array($roundTrip) || $roundTrip['payload'] !== $result->payload || $roundTrip['metadata'] !== $result->metadata) {
            $this->logger->warning('call dedup record skipped: result does not survive JSON', ['tenant_id' => $tenantId]);

            return;
        }

        // The cap is in bytes ('8bit'). The encoding escapes non-ASCII, so characters and bytes coincide today; the
        // explicit unit keeps the cap right should the flags change.
        if (mb_strlen($encoded, '8bit') > self::MAX_RECORD_BYTES) {
            $this->logger->warning('call dedup record skipped: result too large', [
                'tenant_id' => $tenantId,
                'bytes'     => mb_strlen($encoded, '8bit'),
            ]);

            return;
        }

        try {
            $this->redis->connection()->set($this->key($tenantId, $key, 'done'), $encoded, 'EX', $this->ttlSeconds());
        } catch (Throwable $exception) {
            $this->warn('write', $tenantId, $exception);
        }
    }

    private function ttlSeconds(): int
    {
        // An installation default may lie outside the setting's bounds; a zero or negative TTL would disable the ledger.
        return max(1, $this->settings->integer(self::TTL_SETTING)) * 3600;
    }

    private function key(string $tenantId, string $key, string $part): string
    {
        return "flow:call:{$tenantId}:{$key}:{$part}";
    }

    private function warn(string $action, string $tenantId, Throwable $exception): void
    {
        $this->logger->warning('call dedup ledger unavailable: ' . $action, [
            'tenant_id' => $tenantId,
            'exception' => $exception::class,
            'message'   => $exception->getMessage(),
        ]);
    }
}
