<?php

declare(strict_types=1);

namespace App\Domains\Credentials\OAuth2;

use LogicException;
use SensitiveParameter;

/**
 * What a token endpoint answered with a 2xx: the access token and, when the provider gave them, a refresh token, the
 * lifetimes in seconds and the scope it granted. The tokens are private, masked in dumps and never serialized.
 */
final readonly class OAuth2TokenResponse
{
    public function __construct(
        #[SensitiveParameter]
        private string $accessToken,
        #[SensitiveParameter]
        private ?string $refreshToken,
        public ?int $expiresIn,
        public ?int $refreshExpiresIn,
        public ?string $scope,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'accessToken'      => '***',
            'refreshToken'     => null === $this->refreshToken ? null : '***',
            'expiresIn'        => $this->expiresIn,
            'refreshExpiresIn' => $this->refreshExpiresIn,
            'scope'            => $this->scope,
        ];
    }

    /**
     * @throws LogicException always: a token response is never serialized
     */
    public function __serialize(): array
    {
        throw new LogicException('An OAuth2 token response holds tokens and cannot be serialized.');
    }

    /**
     * @param  array<mixed>  $data
     *
     * @throws LogicException always
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('An OAuth2 token response cannot be unserialized.');
    }

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function refreshToken(): ?string
    {
        return $this->refreshToken;
    }
}
