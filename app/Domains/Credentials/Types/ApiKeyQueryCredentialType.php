<?php

declare(strict_types=1);

namespace App\Domains\Credentials\Types;

use Fapost\Foundation\Credentials\Contracts\HttpCredentialTypeInterface;
use Fapost\Foundation\Credentials\DTO\CredentialField;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\DTO\HttpAuthorization;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;

/**
 * An API key sent as a query parameter. Less safe than a header: the key is part of the URL, which proxies, the
 * receiving server and monitoring tools record. The URL-encoded forms of the key are masked in a result too.
 */
final readonly class ApiKeyQueryCredentialType implements HttpCredentialTypeInterface
{
    public const string ID = 'api_key_query';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'API key in the query string';
    }

    public function fields(): array
    {
        return [
            new CredentialField(key: 'parameter', label: 'Parameter name'),
            new CredentialField(key: 'key', label: 'API key', secret: true),
        ];
    }

    public function authorize(CredentialSecret $secret): HttpAuthorization
    {
        $parameter = $secret->setting('parameter');
        $key       = $secret->value('key');

        if (null === $parameter || '' === $parameter || null === $key || '' === $key) {
            throw CredentialUnavailableException::because($secret->id, 'the parameter name or the key is missing');
        }

        return new HttpAuthorization(
            query: [$parameter => $key],
            secrets: array_values(array_unique([$key, rawurlencode($key), urlencode($key)])),
        );
    }
}
