<?php

declare(strict_types=1);

namespace App\Domains\Credentials;

use App\Domains\Credentials\Models\Credential;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Credentials\Contracts\CredentialStoreInterface;
use Fapost\Foundation\Credentials\DTO\CredentialReference;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\Exceptions\CredentialNotFoundException;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Str;

/**
 * The tenant's credentials, read-only, for the flow runtime and the builder's picker.
 *
 * Every read is bounded by the current tenant, which {@see TenantContextInterface::get()} refuses to answer
 * without: another tenant's id, a malformed id and an unknown id are all "not found". Bound scoped, so each job
 * reads in its own tenant.
 */
final readonly class EloquentCredentialStore implements CredentialStoreInterface
{
    public function __construct(private TenantContextInterface $tenants)
    {
    }

    public function references(?string $type = null): array
    {
        $query = Credential::query()
            ->select(['id', 'name', 'type'])
            ->where('tenant_id', $this->tenants->get()->getId())
            ->orderBy('name');

        if (null !== $type) {
            $query->where('type', $type);
        }

        $references = [];

        foreach ($query->get() as $credential) {
            $references[] = new CredentialReference($credential->id, $credential->name, $credential->type);
        }

        return $references;
    }

    /**
     * @throws CredentialUnavailableException when the stored secret cannot be decrypted (the application key changed)
     */
    public function secret(string $id): CredentialSecret
    {
        $tenantId = $this->tenants->get()->getId();

        if (!Str::isUuid($id)) {
            throw CredentialNotFoundException::forId($id);
        }

        $credential = Credential::query()->where('tenant_id', $tenantId)->whereKey($id)->first();

        if (null === $credential) {
            throw CredentialNotFoundException::forId($id);
        }

        try {
            $values = $credential->secret;
        } catch (DecryptException) {
            throw CredentialUnavailableException::because($id, 'the stored secret cannot be decrypted');
        }

        return new CredentialSecret(
            tenantId: $tenantId,
            id: $credential->id,
            type: $credential->type,
            version: self::version($credential),
            settings: array_filter($credential->settings, is_string(...)),
            allowedHosts: array_values(array_filter($credential->allowed_hosts, is_string(...))),
            values: array_filter($values, is_string(...)),
        );
    }

    /**
     * Changes whenever the credential is saved: the save time with its microseconds and a hash of the stored
     * ciphertext, which differs on every write of the secret even within one second of the clock.
     */
    private static function version(Credential $credential): string
    {
        $savedAt = $credential->updated_at?->format('Y-m-d\TH:i:s.u') ?? '';

        return $savedAt . '-' . mb_substr(hash('sha256', (string) $credential->getRawOriginal('secret')), 0, 16);
    }
}
