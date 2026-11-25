<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call;

use App\Domains\Flow\Call\Transports\HttpTransport;
use Fapost\Foundation\Credentials\Contracts\CredentialStoreInterface;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\Exceptions\CredentialNotFoundException;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;

/**
 * The one place that says which transport takes a saved credential and why a credential cannot be used, for the
 * `call` node and the builder's test call alike, so both answer with the same codes.
 *
 * Only the `http` transport applies a credential. Scoped: it reads the current tenant's credentials.
 */
final readonly class CallCredentialResolver
{
    public const string UNSUPPORTED = 'credential_unsupported';

    public const string NOT_FOUND = 'credential_not_found';

    public const string UNAVAILABLE = 'credential_unavailable';

    public function __construct(private CredentialStoreInterface $credentials)
    {
    }

    public function supports(string $transportId): bool
    {
        return HttpTransport::ID === $transportId;
    }

    /**
     * Whether the tenant has a credential with this id; no secret is read.
     */
    public function exists(string $id): bool
    {
        foreach ($this->credentials->references() as $reference) {
            if ($reference->id === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * The credential, or the code that says why it cannot be used: {@see self::UNSUPPORTED},
     * {@see self::NOT_FOUND} or {@see self::UNAVAILABLE}.
     */
    public function resolve(string $id, string $transportId): CredentialSecret|string
    {
        if (!$this->supports($transportId)) {
            return self::UNSUPPORTED;
        }

        try {
            return $this->credentials->secret($id);
        } catch (CredentialNotFoundException) {
            return self::NOT_FOUND;
        } catch (CredentialUnavailableException) {
            return self::UNAVAILABLE;
        }
    }
}
