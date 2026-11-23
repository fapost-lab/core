<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

use DateTimeInterface;

/**
 * The request as a flow sees it, under `flow.webhook`: `method`, `query`, `headers` (lower-cased names, values joined
 * by ", ", without the request's credentials, the signature headers and the signed timestamp), `body` (the decoded JSON, else
 * the form fields, else the text, else null for bytes that are not UTF-8), `delivery_id` and `received_at` (ISO 8601).
 */
final class WebhookPayload
{
    /**
     * @return array{flow: array{webhook: array{method: string, query: array<string, mixed>, headers: array<string, string>, body: mixed, delivery_id: string, received_at: string}}}
     */
    public static function initialState(InboundWebhook $request, WebhookTriggerConfig $config, string $deliveryId, DateTimeInterface $receivedAt): array
    {
        return [
            'flow' => [
                'webhook' => [
                    'method'      => $request->method,
                    'query'       => self::utf8Only($request->query),
                    'headers'     => self::headers($request->headers, $config),
                    'body'        => self::body($request),
                    'delivery_id' => $deliveryId,
                    'received_at' => $receivedAt->format(DateTimeInterface::ATOM),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, list<string|null>|string|null>  $headers
     *
     * @return array<string, string>
     */
    private static function headers(array $headers, WebhookTriggerConfig $config): array
    {
        $kept = [];

        foreach ($headers as $name => $values) {
            $name = mb_strtolower((string) $name);

            if ($config->isStrippedHeader($name)) {
                continue;
            }

            $value = implode(', ', array_map(strval(...), array_filter(is_array($values) ? $values : [$values], static fn (mixed $value): bool => null !== $value)));

            if (mb_check_encoding($value, 'UTF-8')) {
                $kept[$name] = $value;
            }
        }

        ksort($kept);

        return $kept;
    }

    /**
     * Drops string values (and keys) that are not UTF-8: the state is stored as JSON, which cannot hold them.
     *
     * @param  array<array-key, mixed>  $values
     *
     * @return array<array-key, mixed>
     */
    private static function utf8Only(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && ! mb_check_encoding($key, 'UTF-8')) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::utf8Only($value);
            } elseif (! is_string($value) || mb_check_encoding($value, 'UTF-8')) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    private static function body(InboundWebhook $request): mixed
    {
        if ('' === $request->rawBody) {
            return null;
        }

        $decoded = json_decode($request->rawBody, true);

        if (JSON_ERROR_NONE === json_last_error()) {
            return $decoded;
        }

        if ([] !== $request->formFields) {
            return self::utf8Only($request->formFields);
        }

        return mb_check_encoding($request->rawBody, 'UTF-8') ? $request->rawBody : null;
    }
}
