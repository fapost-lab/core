<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Contracts\ApiTokenAuthenticatorInterface;
use App\Domains\Staff\Models\ApiToken;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The tenant API token of `adr-20261119-tenant-api-tokens`: `Authorization: Bearer fpt_<ULID>_<secret>`, looked up by
 * the row id in the current tenant's schema and compared by the SHA-256 of the secret.
 *
 * Order matters. A client that failed {@see self::MAX_FAILURES} times in a minute is refused first, with no query.
 * The shape is checked next, so a malformed value never reaches the `uuid` column (a query error on PostgreSQL). Only
 * then one primary-key lookup. Every failure answers `null` and counts against the client; the reason goes to the log
 * and nowhere else. A token from another tenant is simply not in this schema.
 *
 * `last_used_at` is written at most once a minute per token: an atomic cache `add` decides, and the write is a plain
 * update that fires no model event and touches nothing else. Bound `scoped`: it reads the tenant of the request.
 */
final readonly class HashedApiTokenAuthenticator implements ApiTokenAuthenticatorInterface
{
    /** Failed authentications a client (tenant and IP) may make in a minute before it is refused without a lookup. */
    public const int MAX_FAILURES = 20;

    private const int DECAY_SECONDS = 60;

    private const int LAST_USED_EVERY_SECONDS = 60;

    public function __construct(
        private TenantContextInterface $tenants,
        private RateLimiter $limiter,
        private Cache $cache,
        private LoggerInterface $logger,
    ) {
    }

    public function authenticate(Request $request): ?ApiToken
    {
        $tenantId   = $this->tenants->get()->getId();
        $failureKey = 'api-token-auth-failed:' . $tenantId . ':' . (string) $request->ip();

        if ($this->limiter->tooManyAttempts($failureKey, self::MAX_FAILURES)) {
            $this->logger->warning('api_token.auth_throttled', ['tenant_id' => $tenantId, 'ip' => $request->ip()]);

            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => $this->limiter->availableIn($failureKey),
            ]);
        }

        $token = $this->find($request, $tenantId);

        if (null === $token) {
            $this->limiter->hit($failureKey, self::DECAY_SECONDS);

            return null;
        }

        $this->touch($token, $tenantId);

        return $token;
    }

    private function find(Request $request, string $tenantId): ?ApiToken
    {
        $presented = $request->bearerToken();

        if (null === $presented || '' === $presented) {
            return $this->fail('missing', $tenantId, null);
        }

        if (1 !== preg_match(ApiToken::PATTERN, $presented, $parts)) {
            return $this->fail('malformed', $tenantId, null);
        }

        $id    = ApiToken::rowIdOf($parts[1]);
        $token = ApiToken::query()->find($id);

        if (! $token instanceof ApiToken) {
            return $this->fail('unknown', $tenantId, $id);
        }

        if (! hash_equals($token->token_hash, ApiToken::hashSecret($parts[2]))) {
            return $this->fail('secret_mismatch', $tenantId, $id);
        }

        if ($token->isRevoked()) {
            return $this->fail('revoked', $tenantId, $id);
        }

        if ($token->isExpired()) {
            return $this->fail('expired', $tenantId, $id);
        }

        return $token;
    }

    private function fail(string $reason, string $tenantId, ?string $tokenId): null
    {
        $this->logger->info('api_token.auth_failed', [
            'reason'    => $reason,
            'tenant_id' => $tenantId,
            'token_id'  => $tokenId,
        ]);

        return null;
    }

    private function touch(ApiToken $token, string $tenantId): void
    {
        try {
            if (! $this->cache->add('api-token-used:' . $tenantId . ':' . $token->getKey(), true, self::LAST_USED_EVERY_SECONDS)) {
                return;
            }

            $now = Carbon::now();

            ApiToken::query()->whereKey($token->getKey())->toBase()->update(['last_used_at' => $now]);
            $token->last_used_at = $now;
            $token->syncOriginalAttribute('last_used_at');
        } catch (Throwable $exception) {
            // A failed bookkeeping write never refuses a valid token.
            report($exception);
        }
    }
}
