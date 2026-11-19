<?php

declare(strict_types=1);

namespace App\Domains\Credentials\Types;

use Fapost\Foundation\Credentials\Contracts\HttpCredentialTypeInterface;
use Fapost\Foundation\Credentials\DTO\CredentialField;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\DTO\HttpAuthorization;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;

/**
 * An API key sent in a header of the admin's choosing: `<header>: <prefix><key>`.
 */
final readonly class ApiKeyHeaderCredentialType implements HttpCredentialTypeInterface
{
    public const string ID = 'api_key_header';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'API key in a header';
    }

    public function fields(): array
    {
        return [
            new CredentialField(key: 'header', label: 'Header name'),
            new CredentialField(key: 'prefix', label: 'Value prefix', required: false),
            new CredentialField(key: 'key', label: 'API key', secret: true),
        ];
    }

    public function authorize(CredentialSecret $secret): HttpAuthorization
    {
        $header = $secret->setting('header');
        $key    = $secret->value('key');

        if (null === $header || '' === $header || null === $key || '' === $key) {
            throw CredentialUnavailableException::because($secret->id, 'the header name or the key is missing');
        }

        if (1 !== preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $header)) {
            throw CredentialUnavailableException::because($secret->id, 'the header name is not valid');
        }

        return new HttpAuthorization(
            headers: [$header => ($secret->setting('prefix') ?? '') . $key],
            secrets: [$key],
        );
    }
}
