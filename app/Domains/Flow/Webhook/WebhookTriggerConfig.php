<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

use Fapost\Foundation\Channel\Ingress\IngressSpec;
use Fapost\Foundation\Channel\Ingress\IngressTimestamp;
use Fapost\Foundation\Channel\Ingress\SignatureScheme;
use InvalidArgumentException;
use ValueError;

/**
 * The config of a webhook trigger, as `flow_triggers.config` and the draft carry it: how a request is checked and
 * where its delivery id is, and nothing secret. The secret is the endpoint's ({@see \App\Domains\Flow\Models\FlowWebhookEndpoint}).
 *
 * Keys: `verification` (`hmac_sha256` or `header_equals`), `signature_header`; for HMAC only `signature_prefix`
 * (e.g. `sha256=`), `signed_payload` (the signed string, `{raw}` once) and `timestamp_header` with
 * `timestamp_tolerance` (seconds, default 300) — a timestamp only together with a `signed_payload` that includes
 * `{header.<timestamp_header>}`, so the signature covers it; `delivery_id` (one placeholder `{header.Name}` or `{body.path}`, alternatives joined
 * by `|`, or empty); `response_mode` — `async` (202 once the run is queued, the default) or `sync` (the request waits
 * for the flow's answer, {@see WebhookSyncWait}). Any other key is refused — `secret` above all.
 *
 * The check itself is Foundation's {@see IngressSpec}, the same one channel webhooks run.
 */
final readonly class WebhookTriggerConfig
{
    public const string VERIFICATION_HMAC_SHA256 = 'hmac_sha256';

    public const string VERIFICATION_HEADER_EQUALS = 'header_equals';

    public const string RESPONSE_ASYNC = 'async';

    public const string RESPONSE_SYNC = 'sync';

    public const int DEFAULT_TIMESTAMP_TOLERANCE = 300;

    /** @var list<string> */
    private const array KEYS = [
        'verification',
        'signature_header',
        'signature_prefix',
        'timestamp_header',
        'timestamp_tolerance',
        'signed_payload',
        'delivery_id',
        'response_mode',
    ];

    private const string HEADER_NAME = '/^[A-Za-z0-9-]{1,64}$/D';

    private const string PLACEHOLDER_ALTERNATIVE = '/^(header\.[A-Za-z0-9-]{1,64}|body\.[A-Za-z0-9_-]{1,64}(\.[A-Za-z0-9_-]{1,64}){0,9})$/D';

    /** Headers never handed to the flow, whatever the config says: credentials of the request itself. */
    private const array ALWAYS_STRIPPED_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'php-auth-user', 'php-auth-pw', 'php-auth-digest'];

    private function __construct(
        public string $verification,
        public string $signatureHeader,
        public string $signaturePrefix,
        public ?string $timestampHeader,
        public int $timestampTolerance,
        public ?string $signedPayload,
        public string $deliveryIdTemplate,
        public string $responseMode,
    ) {
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidArgumentException when the config is not a valid webhook trigger config
     */
    public static function fromConfig(array $config): self
    {
        $unknown = array_diff(array_keys($config), self::KEYS);

        if ([] !== $unknown) {
            // `secret`, `path` and `method` of the old shape land here: the secret lives on the endpoint only.
            throw new InvalidArgumentException('Invalid webhook trigger config: unknown key(s) ' . implode(', ', $unknown) . '.');
        }

        $verification = $config['verification'] ?? null;

        if (self::VERIFICATION_HMAC_SHA256 !== $verification && self::VERIFICATION_HEADER_EQUALS !== $verification) {
            throw new InvalidArgumentException('Invalid webhook trigger config: verification must be hmac_sha256 or header_equals.');
        }

        $isHmac = self::VERIFICATION_HMAC_SHA256 === $verification;

        $signatureHeader = self::headerName($config['signature_header'] ?? null, 'signature_header');
        $prefix          = self::optionalString($config, 'signature_prefix', 64);
        $timestampHeader = self::optionalString($config, 'timestamp_header', 64);
        $signedPayload   = self::optionalString($config, 'signed_payload', 512);
        $tolerance       = $config['timestamp_tolerance'] ?? null;

        if (! $isHmac && (null !== $prefix || null !== $timestampHeader || null !== $signedPayload || null !== $tolerance)) {
            throw new InvalidArgumentException('Invalid webhook trigger config: a prefix, a timestamp and a signed template need hmac_sha256.');
        }

        if (null !== $timestampHeader) {
            self::headerName($timestampHeader, 'timestamp_header');

            // A timestamp outside the signature proves nothing: a captured request replays with a fresh one.
            if (null === $signedPayload || ! str_contains($signedPayload, '{header.' . $timestampHeader . '}')) {
                throw new InvalidArgumentException("Invalid webhook trigger config: with a timestamp_header the signed_payload must contain {header.{$timestampHeader}} as well as {raw}.");
            }
        }

        if (null !== $tolerance && (null === $timestampHeader || ! is_int($tolerance) || $tolerance < 1 || $tolerance > IngressTimestamp::MAX_TOLERANCE_SECONDS)) {
            throw new InvalidArgumentException('Invalid webhook trigger config: timestamp_tolerance needs a timestamp_header and is 1 to ' . IngressTimestamp::MAX_TOLERANCE_SECONDS . ' seconds.');
        }

        $deliveryId = self::optionalString($config, 'delivery_id', 512) ?? '';

        $responseMode = $config['response_mode'] ?? self::RESPONSE_ASYNC;

        if (self::RESPONSE_ASYNC !== $responseMode && self::RESPONSE_SYNC !== $responseMode) {
            throw new InvalidArgumentException('Invalid webhook trigger config: response_mode must be async or sync.');
        }

        $parsed = new self(
            verification: $verification,
            signatureHeader: $signatureHeader,
            signaturePrefix: $prefix ?? '',
            timestampHeader: $timestampHeader,
            timestampTolerance: is_int($tolerance) ? $tolerance : self::DEFAULT_TIMESTAMP_TOLERANCE,
            signedPayload: $signedPayload,
            deliveryIdTemplate: $deliveryId,
            responseMode: $responseMode,
        );

        if ('' !== $deliveryId) {
            $parsed->assertDeliveryIdTemplate($deliveryId);
        }

        // Foundation refuses the combinations it cannot run (a signed template without exactly one {raw}); refuse
        // them here, at publish, rather than with a 500 when a request arrives.
        try {
            $parsed->ingressSpec();
        } catch (ValueError $error) {
            throw new InvalidArgumentException('Invalid webhook trigger config: ' . $error->getMessage(), 0, $error);
        }

        return $parsed;
    }

    /** Whether a request waits for the flow's answer instead of getting 202 at once. */
    public function isSync(): bool
    {
        return self::RESPONSE_SYNC === $this->responseMode;
    }

    public function ingressSpec(): IngressSpec
    {
        $isHmac = self::VERIFICATION_HMAC_SHA256 === $this->verification;

        return new IngressSpec(
            scheme: $isHmac ? SignatureScheme::HmacSha256 : SignatureScheme::HeaderEquals,
            parameter: $this->signatureHeader,
            prefix: $isHmac ? $this->signaturePrefix : '',
            idempotencyTemplate: $this->deliveryIdTemplate,
            signedPayload: $this->signedPayload,
            timestamp: null === $this->timestampHeader ? null : new IngressTimestamp($this->timestampHeader, $this->timestampTolerance),
        );
    }

    /**
     * Whether a header is kept from the flow: the request's own credentials, the configured signature header (or the
     * secret itself, for `header_equals`), the signed timestamp, and any header whose name says it is a signature —
     * a sender may sign twice (GitHub's `X-Hub-Signature` next to `X-Hub-Signature-256`).
     *
     * The one list for both the headers the flow gets ({@see WebhookPayload}) and the headers a `delivery_id` may not
     * name: an id ends up in the log, the run's state, the fire key and the answer to the sender.
     */
    public function isStrippedHeader(string $name): bool
    {
        $name = mb_strtolower($name);

        if (in_array($name, self::ALWAYS_STRIPPED_HEADERS, true) || $name === mb_strtolower($this->signatureHeader) || str_contains($name, 'signature')) {
            return true;
        }

        return null !== $this->timestampHeader && $name === mb_strtolower($this->timestampHeader);
    }

    private static function headerName(mixed $value, string $key): string
    {
        if (! is_string($value) || 1 !== preg_match(self::HEADER_NAME, $value)) {
            throw new InvalidArgumentException("Invalid webhook trigger config: {$key} must be a header name.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function optionalString(array $config, string $key, int $maxLength): ?string
    {
        $value = $config[$key] ?? null;

        if (null === $value || '' === $value) {
            return null;
        }

        if (! is_string($value) || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("Invalid webhook trigger config: {$key} must be a string of at most {$maxLength} characters.");
        }

        return $value;
    }

    /**
     * Exactly one placeholder and no literal text: an unresolved placeholder becomes an empty string, and literal
     * text around it would turn every request without an id into the same constant id — one run, then duplicates.
     * A header alternative may not name a header kept from the flow ({@see isStrippedHeader()}): the secret or the
     * signature would become the delivery id, shown in the log, the run's state and the answer.
     */
    private function assertDeliveryIdTemplate(string $template): void
    {
        if (1 !== preg_match('/^\{([^{}]+)\}$/D', $template, $matches)) {
            throw new InvalidArgumentException('Invalid webhook trigger config: delivery_id must be one placeholder such as {header.X-Delivery-Id} or {body.id}.');
        }

        foreach (explode('|', $matches[1]) as $alternative) {
            if (1 !== preg_match(self::PLACEHOLDER_ALTERNATIVE, $alternative)) {
                throw new InvalidArgumentException('Invalid webhook trigger config: delivery_id must be one placeholder such as {header.X-Delivery-Id} or {body.id}.');
            }

            if (str_starts_with($alternative, 'header.') && $this->isStrippedHeader(mb_substr($alternative, mb_strlen('header.')))) {
                throw new InvalidArgumentException("Invalid webhook trigger config: delivery_id may not name {{$alternative}}: the credentials, signature and timestamp headers never leave the check.");
            }
        }
    }
}
