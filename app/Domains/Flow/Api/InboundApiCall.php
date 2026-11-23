<?php

declare(strict_types=1);

namespace App\Domains\Flow\Api;

/**
 * A call to `POST /api/v1/flow-triggers/{trigger}/runs` as the receiver needs it, free of the framework request and of
 * the Staff token model: the token that authenticated it is known by its id and name only.
 */
final readonly class InboundApiCall
{
    public function __construct(
        public string $triggerId,
        public ?string $contentType,
        public string $rawBody,
        public ?string $idempotencyKey,
        public string $tokenId,
        public string $tokenName,
    ) {
    }

    /** Whether the body is declared JSON: `application/json`, or a `+json` type such as `application/vnd.api+json`. */
    public function isJson(): bool
    {
        $type = mb_strtolower(mb_trim(explode(';', (string) $this->contentType)[0]));

        return 'application/json' === $type || (str_starts_with($type, 'application/') && str_ends_with($type, '+json'));
    }
}
