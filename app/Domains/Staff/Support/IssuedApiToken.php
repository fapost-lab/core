<?php

declare(strict_types=1);

namespace App\Domains\Staff\Support;

use App\Domains\Staff\Models\ApiToken;
use SensitiveParameter;

/**
 * A token just issued or rotated, with its plain value: the only moment the plain value exists. It is shown once and
 * never stored.
 */
final readonly class IssuedApiToken
{
    public function __construct(
        public ApiToken $token,
        #[SensitiveParameter]
        public string $plain,
    ) {
    }
}
