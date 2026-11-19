<?php

declare(strict_types=1);

namespace App\Domains\Credentials\Types;

use Fapost\Foundation\Credentials\Contracts\HttpCredentialTypeInterface;
use Fapost\Foundation\Credentials\DTO\CredentialField;
use Fapost\Foundation\Credentials\DTO\CredentialSecret;
use Fapost\Foundation\Credentials\DTO\HttpAuthorization;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;

/**
 * A user name and password sent as `Authorization: Basic base64(user:password)`. The user name is a setting and
 * is not masked in a result; the password and the encoded pair are.
 */
final readonly class BasicCredentialType implements HttpCredentialTypeInterface
{
    public const string ID = 'basic';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return 'Basic authentication';
    }

    public function fields(): array
    {
        return [
            new CredentialField(key: 'username', label: 'Username'),
            new CredentialField(key: 'password', label: 'Password', secret: true),
        ];
    }

    public function authorize(CredentialSecret $secret): HttpAuthorization
    {
        $username = $secret->setting('username');
        $password = $secret->value('password');

        if (null === $username || '' === $username || null === $password || '' === $password) {
            throw CredentialUnavailableException::because($secret->id, 'the username or the password is missing');
        }

        $encoded = base64_encode($username . ':' . $password);

        return new HttpAuthorization(
            headers: ['Authorization' => 'Basic ' . $encoded],
            secrets: [$password, $encoded],
        );
    }
}
