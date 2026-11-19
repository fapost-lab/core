<?php

declare(strict_types=1);

namespace App\Domains\Credentials\Types;

use Fapost\Foundation\Credentials\Contracts\HttpCredentialTypeInterface;
use Fapost\Foundation\Credentials\DTO\CredentialField;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\DTO\HttpAuthorization;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;

/**
 * A token sent as `Authorization: Bearer <token>`.
 */
final readonly class BearerCredentialType implements HttpCredentialTypeInterface
{
    public const string ID = 'bearer';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Bearer token';
    }

    public function fields(): array
    {
        return [
            new CredentialField(key: 'token', label: 'Token', secret: true),
        ];
    }

    public function authorize(CredentialSecret $secret): HttpAuthorization
    {
        $token = $secret->value('token');

        if (null === $token || '' === $token) {
            throw CredentialUnavailableException::because($secret->id, 'the token is missing');
        }

        return new HttpAuthorization(
            headers: ['Authorization' => 'Bearer ' . $token],
            secrets: [$token],
        );
    }
}
