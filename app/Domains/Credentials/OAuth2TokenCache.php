<?php

declare(strict_types=1);

namespace App\Domains\Credentials;

use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The OAuth2 access tokens fetched for credentials, so N calls cost one token request. Keyed
 * `credentials:oauth2:{tenant}:{credential}:{version}`: a credential saved again has a new version and stops
 * matching its old token. A token is encrypted in Redis and lives for its `expires_in` less a margin.
 *
 * Holds no tenant state. A Redis or decryption failure is logged (never with a token) and answered as a miss, so
 * a call is never blocked by the cache: the token is fetched again.
 */
final readonly class OAuth2TokenCache
{
    public const int DEFAULT_TTL_SECONDS = 300;

    public const int EXPIRY_MARGIN_SECONDS = 30;

    public function __construct(
        private RedisFactory $redis,
        private Encrypter $encrypter,
        private RedisKeyPurger $purger,
        private LoggerInterface $logger,
    ) {
    }

    /** The key prefix shared by every token of one tenant; the tenant forgetter matches on it. */
    public static function tenantKeyPrefix(string $tenantId): string
    {
        return "credentials:oauth2:{$tenantId}:";
    }

    public function get(CredentialSecret $secret): ?string
    {
        try {
            $raw = $this->redis->connection()->get($this->key($secret));

            if (!is_string($raw) || '' === $raw) {
                return null;
            }

            $token = $this->encrypter->decryptString($raw);

            return '' === $token ? null : $token;
        } catch (Throwable $exception) {
            $this->warn('read', $secret->tenantId, $secret->id, $exception);

            return null;
        }
    }

    /**
     * @param  int|null  $expiresIn  seconds the token is valid for, as the endpoint said; null when it did not
     */
    public function put(CredentialSecret $secret, string $token, ?int $expiresIn): void
    {
        $ttl = null === $expiresIn ? self::DEFAULT_TTL_SECONDS : max(1, $expiresIn - self::EXPIRY_MARGIN_SECONDS);

        try {
            $this->redis->connection()->set($this->key($secret), $this->encrypter->encryptString($token), 'EX', $ttl);
        } catch (Throwable $exception) {
            $this->warn('write', $secret->tenantId, $secret->id, $exception);
        }
    }

    /**
     * Drops the tokens of every version of the credential.
     */
    public function forget(string $tenantId, string $credentialId): void
    {
        try {
            $this->purger->purge(self::tenantKeyPrefix($tenantId) . $credentialId . ':*');
        } catch (Throwable $exception) {
            $this->warn('forget', $tenantId, $credentialId, $exception);
        }
    }

    private function key(CredentialSecret $secret): string
    {
        return self::tenantKeyPrefix($secret->tenantId) . "{$secret->id}:{$secret->version}";
    }

    private function warn(string $action, string $tenantId, string $credentialId, Throwable $exception): void
    {
        $this->logger->warning('credential token cache unavailable: ' . $action, [
            'tenant_id'     => $tenantId,
            'credential_id' => $credentialId,
            'exception'     => $exception::class,
        ]);
    }
}
