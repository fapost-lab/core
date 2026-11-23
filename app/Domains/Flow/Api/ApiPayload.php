<?php

declare(strict_types=1);

namespace App\Domains\Flow\Api;

use DateTimeInterface;

/**
 * The call as a flow sees it, under `flow.api`: `body` (the decoded JSON object), `request_id` (the `Idempotency-Key`
 * as sent, or the id made for a call without one), `token` (`id` and `name` of the token that called) and
 * `received_at` (ISO 8601). No header of the call: they carry its credentials, and nothing the flow needs.
 */
final class ApiPayload
{
    /**
     * @param  array<string, mixed>  $body
     *
     * @return array{flow: array{api: array{body: array<string, mixed>, request_id: string, token: array{id: string, name: string}, received_at: string}}}
     */
    public static function initialState(InboundApiCall $call, array $body, string $requestId, DateTimeInterface $receivedAt): array
    {
        return [
            'flow' => [
                'api' => [
                    'body'        => $body,
                    'request_id'  => $requestId,
                    'token'       => ['id' => $call->tokenId, 'name' => $call->tokenName],
                    'received_at' => $receivedAt->format(DateTimeInterface::ATOM),
                ],
            ],
        ];
    }
}
