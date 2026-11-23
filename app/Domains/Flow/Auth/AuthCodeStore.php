<?php

declare(strict_types=1);

namespace App\Domains\Flow\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use SensitiveParameter;

/**
 * Where the one-time codes of `auth_request@2` live while a contact is expected to type one back.
 *
 * Only a keyed hash of the code is kept — HMAC-SHA256 under a key derived from the application key, over the
 * tenant, session, node and code — in the cache, with the code's lifetime as its TTL. Nothing of the code is
 * written to the session state, the node trace or a log, and a hash read from the cache cannot be brute-forced
 * without the application key. One code is pending per (tenant, session, node): issuing a new one replaces it.
 *
 * A record names the send it belongs to, so a retried step that issues the same send again keeps the code its first
 * job is delivering, and a late delivery failure of an older send does not spoil a newer code. A cache that lost
 * the record (a restart without persistence) reads as {@see AuthCodeCheck::Missing}: the contact asks for a new one.
 *
 * The per-destination counter limits how many codes one phone number or address receives in an hour, within the
 * tenant, whatever flow or contact asks for them.
 */
final readonly class AuthCodeStore
{
    /** Codes one destination may receive within {@see self::DESTINATION_WINDOW_SECONDS}. */
    public const int DESTINATION_LIMIT = 5;

    public const int DESTINATION_WINDOW_SECONDS = 3600;

    private const string KEY_PREFIX = 'flow:auth_code:';

    private const string DESTINATION_PREFIX = 'flow:auth_code_destination:';

    private string $hashKey;

    public function __construct(
        private Repository $cache,
        #[SensitiveParameter]
        string $applicationKey,
    ) {
        $this->hashKey = hash_hmac('sha256', 'fapost.flow.auth_code', $applicationKey);
    }

    /**
     * Whether the send (`<challenge>:<send number>`) was already issued and its code is still pending: a retried
     * step must then keep the code its first job is delivering.
     */
    public function isIssued(string $tenantId, string $sessionId, string $nodeId, string $sendToken): bool
    {
        $record = $this->cache->get($this->key($tenantId, $sessionId, $nodeId));

        return is_array($record) && ($record['send'] ?? null) === $sendToken;
    }

    /**
     * Keep the hash of a new code for the send, replacing any pending code of the node.
     */
    public function issue(
        string $tenantId,
        string $sessionId,
        string $nodeId,
        string $sendToken,
        #[SensitiveParameter]
        string $code,
        int $ttlMinutes,
    ): void {
        $expiresAt = CarbonImmutable::now()->addMinutes($ttlMinutes);

        $this->cache->put($this->key($tenantId, $sessionId, $nodeId), [
            'send'       => $sendToken,
            'hash'       => $this->hash($tenantId, $sessionId, $nodeId, $code),
            'expires_at' => $expiresAt->getTimestamp(),
            'failed'     => false,
        ], $expiresAt);
    }

    /**
     * Compare a typed code with the pending one in constant time.
     */
    public function check(
        string $tenantId,
        string $sessionId,
        string $nodeId,
        #[SensitiveParameter]
        string $candidate,
    ): AuthCodeCheck {
        $record = $this->cache->get($this->key($tenantId, $sessionId, $nodeId));

        if (! is_array($record) || ! is_string($record['hash'] ?? null)) {
            return AuthCodeCheck::Missing;
        }

        if ((int) ($record['expires_at'] ?? 0) <= CarbonImmutable::now()->getTimestamp()) {
            return AuthCodeCheck::Missing;
        }

        if (true === ($record['failed'] ?? false)) {
            return AuthCodeCheck::DeliveryFailed;
        }

        return hash_equals($record['hash'], $this->hash($tenantId, $sessionId, $nodeId, $candidate))
            ? AuthCodeCheck::Match
            : AuthCodeCheck::Mismatch;
    }

    /**
     * Whether the sender reported the pending code undelivered.
     */
    public function deliveryFailed(string $tenantId, string $sessionId, string $nodeId): bool
    {
        $record = $this->cache->get($this->key($tenantId, $sessionId, $nodeId));

        return is_array($record) && true === ($record['failed'] ?? false);
    }

    /**
     * Record that the send never left; ignored when a newer send has replaced it.
     */
    public function markDeliveryFailed(string $tenantId, string $sessionId, string $nodeId, string $sendToken): void
    {
        $key    = $this->key($tenantId, $sessionId, $nodeId);
        $record = $this->cache->get($key);

        if (! is_array($record) || ($record['send'] ?? null) !== $sendToken) {
            return;
        }

        $expiresAt = CarbonImmutable::createFromTimestamp((int) ($record['expires_at'] ?? 0));

        if ($expiresAt->isPast()) {
            return;
        }

        $this->cache->put($key, [...$record, 'failed' => true], $expiresAt);
    }

    /**
     * Mark the pending code used. It keeps matching for the step that used it, should that step be retried before
     * its state was saved; the node's next challenge replaces the record, so it is never accepted by another one.
     */
    public function markUsed(string $tenantId, string $sessionId, string $nodeId): void
    {
        $key    = $this->key($tenantId, $sessionId, $nodeId);
        $record = $this->cache->get($key);

        if (! is_array($record)) {
            return;
        }

        $expiresAt = CarbonImmutable::createFromTimestamp((int) ($record['expires_at'] ?? 0));

        if ($expiresAt->isPast()) {
            return;
        }

        $this->cache->put($key, [...$record, 'used' => true], $expiresAt);
    }

    /**
     * Drop the pending code: used up or abandoned.
     */
    public function forget(string $tenantId, string $sessionId, string $nodeId): void
    {
        $this->cache->forget($this->key($tenantId, $sessionId, $nodeId));
    }

    /**
     * Whether the destination can still receive a code this hour, without counting one.
     */
    public function destinationHasRoom(string $tenantId, string $destination): bool
    {
        $count = $this->cache->get($this->destinationKey($tenantId, $destination));

        return ! is_numeric($count) || (int) $count < self::DESTINATION_LIMIT;
    }

    /**
     * Count one code sent to the destination and say whether it is still within the hourly limit. A refused send
     * is counted too, so hammering a destination does not reopen it.
     */
    public function allowDestination(string $tenantId, string $destination): bool
    {
        $key = $this->destinationKey($tenantId, $destination);

        $this->cache->add($key, 0, self::DESTINATION_WINDOW_SECONDS);

        $count = $this->cache->increment($key);

        return is_int($count) && $count <= self::DESTINATION_LIMIT;
    }

    private function destinationKey(string $tenantId, string $destination): string
    {
        return self::DESTINATION_PREFIX . $tenantId . ':' . hash('sha256', mb_strtolower(mb_trim($destination)));
    }

    private function key(string $tenantId, string $sessionId, string $nodeId): string
    {
        return self::KEY_PREFIX . $tenantId . ':' . $sessionId . ':' . $nodeId;
    }

    private function hash(string $tenantId, string $sessionId, string $nodeId, #[SensitiveParameter] string $code): string
    {
        return hash_hmac('sha256', $tenantId . '|' . $sessionId . '|' . $nodeId . '|' . $code, $this->hashKey);
    }
}
