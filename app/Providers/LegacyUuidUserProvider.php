<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Prevent invalid legacy session identifiers from reaching UUID-backed auth queries.
 */
final class LegacyUuidUserProvider extends EloquentUserProvider
{
    /**
     * Retrieve a user by their unique identifier.
     *
     * @param  mixed  $identifier
     */
    public function retrieveById($identifier): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        if ( ! $this->isValidAuthIdentifier($identifier)) {
            return null;
        }

        return parent::retrieveById($identifier);
    }

    /**
     * Retrieve a user by their unique identifier and "remember me" token.
     *
     * @param  mixed  $identifier
     */
    public function retrieveByToken($identifier, #[SensitiveParameter] $token): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        if ( ! $this->isValidAuthIdentifier($identifier)) {
            return null;
        }

        return parent::retrieveByToken($identifier, $token);
    }

    /**
     * @param  mixed  $identifier
     */
    private function isValidAuthIdentifier(mixed $identifier): bool
    {
        $model = $this->createModel();

        if ($model->getIncrementing() || 'string' !== $model->getKeyType()) {
            return true;
        }

        return is_string($identifier) && Str::isUuid($identifier);
    }
}
