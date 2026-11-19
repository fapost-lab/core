<?php

declare(strict_types=1);

namespace Tests\Support;

use Fapost\Foundation\Credentials\Contracts\CredentialStoreInterface;
use Fapost\Foundation\Credentials\DTO\CredentialReference;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\Exceptions\CredentialNotFoundException;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;

/**
 * {@see CredentialStoreInterface} for tests that need no database: keeps the secrets in an array, and counts reads.
 */
final class InMemoryCredentialStore implements CredentialStoreInterface
{
    /**
     * @var array<string, CredentialSecret>
     */
    public array $secrets = [];

    /**
     * @var list<string>  ids whose secret cannot be read
     */
    public array $unavailable = [];

    /**
     * How many times {@see self::secret()} was asked.
     */
    public int $reads = 0;

    public function add(CredentialSecret $secret): void
    {
        $this->secrets[$secret->id] = $secret;
    }

    public function references(?string $type = null): array
    {
        $references = [];

        foreach ($this->secrets as $secret) {
            if (null === $type || $secret->type === $type) {
                $references[] = new CredentialReference($secret->id, 'Credential ' . $secret->id, $secret->type);
            }
        }

        return $references;
    }

    public function secret(string $id): CredentialSecret
    {
        $this->reads++;

        if (in_array($id, $this->unavailable, true)) {
            throw CredentialUnavailableException::because($id, 'unreadable in the test');
        }

        return $this->secrets[$id] ?? throw CredentialNotFoundException::forId($id);
    }
}
