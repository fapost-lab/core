export type WebhookConfig = Record<string, unknown>

export interface WebhookPreset {
    value: string
    label: string
    config: WebhookConfig
}

export const WEBHOOK_PRESETS: WebhookPreset[] = [
    {
        value: 'hmac',
        label: 'HMAC-SHA256 (generic)',
        config: { verification: 'hmac_sha256', signature_header: 'X-Signature', signature_prefix: 'sha256=', response_mode: 'async' },
    },
    {
        value: 'hmac_timestamp',
        label: 'HMAC with timestamp',
        config: {
            verification: 'hmac_sha256',
            signature_header: 'X-Signature',
            signature_prefix: 'sha256=',
            timestamp_header: 'X-Timestamp',
            signed_payload: '{header.X-Timestamp}.{raw}',
            response_mode: 'async',
        },
    },
    {
        value: 'github',
        label: 'GitHub',
        config: {
            verification: 'hmac_sha256',
            signature_header: 'X-Hub-Signature-256',
            signature_prefix: 'sha256=',
            delivery_id: '{header.X-GitHub-Delivery}',
            response_mode: 'async',
        },
    },
    {
        value: 'header',
        label: 'Secret in a header',
        config: { verification: 'header_equals', signature_header: 'X-Webhook-Secret', response_mode: 'async' },
    },
]

/** The config a new webhook trigger starts with: the generic HMAC preset. */
export function defaultWebhookConfig(): WebhookConfig {
    return webhookConfigFromPreset('hmac')
}

/** A preset replaces the whole config, so keys of an old format do not survive. */
export function webhookConfigFromPreset(value: string): WebhookConfig {
    const preset = WEBHOOK_PRESETS.find((item) => item.value === value) ?? WEBHOOK_PRESETS[0]

    return { ...preset.config }
}

/** A trigger saved before the verification model: it has `method`/`path`/`secret` or no `verification`. */
export function isLegacyWebhookConfig(config: WebhookConfig | null | undefined): boolean {
    const cfg = config ?? {}

    return 'method' in cfg || 'path' in cfg || 'secret' in cfg || typeof cfg.verification !== 'string'
}

/** Sets or clears one key; an empty value is omitted rather than sent as ''. Always keeps `response_mode: 'async'`. */
export function withWebhookField(config: WebhookConfig, key: string, value: string | number | null): WebhookConfig {
    const next: WebhookConfig = { ...config, response_mode: 'async' }

    if (value === null || value === '' || (typeof value === 'number' && Number.isNaN(value))) {
        delete next[key]
    } else {
        next[key] = value
    }

    if (key === 'timestamp_header') {
        if (next.timestamp_header === undefined) {
            delete next.timestamp_tolerance
        } else if (next.signed_payload === undefined) {
            next.signed_payload = `{header.${String(next.timestamp_header)}}.{raw}`
        }
    }

    return next
}

/** Switching the verification type drops the keys that only make sense for HMAC. */
export function withWebhookVerification(config: WebhookConfig, verification: string): WebhookConfig {
    const next: WebhookConfig = { ...config, verification, response_mode: 'async' }

    if (verification !== 'hmac_sha256') {
        delete next.signature_prefix
        delete next.timestamp_header
        delete next.timestamp_tolerance
        delete next.signed_payload
    }

    return next
}
