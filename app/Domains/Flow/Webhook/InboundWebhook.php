<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

/**
 * A request to `/hooks/flow/{token}` as the receiver needs it, free of the framework request.
 */
final readonly class InboundWebhook
{
    /**
     * @param  array<string, list<string|null>|string|null>  $headers     as Symfony's header bag gives them (lower-cased names)
     * @param  array<string, mixed>                          $query       the parsed query string
     * @param  array<string, mixed>                          $formFields  the parsed body of a form post, else empty
     */
    public function __construct(
        public string $token,
        public string $method,
        public string $rawBody,
        public array $headers,
        public array $query,
        public array $formFields,
        public ?string $clientIp,
    ) {
    }

    /** Whether the body is `multipart/*`, which PHP parses itself and keeps from {@see $rawBody}. */
    public function isMultipart(): bool
    {
        $values = $this->headers['content-type'] ?? null;
        $value  = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;

        return str_starts_with(mb_strtolower(mb_ltrim($value)), 'multipart/');
    }
}
