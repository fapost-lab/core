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

/** A preset replaces the whole config, so keys of an old format do not survive; the response mode is kept when given. */
export function webhookConfigFromPreset(value: string, responseMode: 'async' | 'sync' = 'async'): WebhookConfig {
    const preset = WEBHOOK_PRESETS.find((item) => item.value === value) ?? WEBHOOK_PRESETS[0]

    return { ...preset.config, response_mode: responseMode }
}

/** Switching the response mode touches nothing else. */
export function withWebhookResponseMode(config: WebhookConfig, mode: string): WebhookConfig {
    return { ...config, response_mode: mode === 'sync' ? 'sync' : 'async' }
}

/** A trigger saved before the verification model: it has `method`/`path`/`secret` or no `verification`. */
export function isLegacyWebhookConfig(config: WebhookConfig | null | undefined): boolean {
    const cfg = config ?? {}

    return 'method' in cfg || 'path' in cfg || 'secret' in cfg || typeof cfg.verification !== 'string'
}

/** The response mode a config has: `sync` (the sender waits for the flow's answer) or `async`, the default. */
export function webhookResponseMode(config: WebhookConfig | null | undefined): 'async' | 'sync' {
    return config?.response_mode === 'sync' ? 'sync' : 'async'
}

/** The signed string a timestamp header is given until the author writes their own: `{header.<name>}.{raw}`. */
function defaultSignedPayload(timestampHeader: unknown): string | undefined {
    return typeof timestampHeader === 'string' && timestampHeader !== '' ? `{header.${timestampHeader}}.{raw}` : undefined
}

/**
 * Sets or clears one key; an empty value is omitted rather than sent as ''. Keeps the response mode, `async` when unset.
 *
 * The timestamp header carries its signed string along while that string is still the one filled in for the previous
 * header (it is typed a character at a time): an unset string, or `{header.<previous>}.{raw}`, follows the new name and
 * goes with the header when it is cleared. A string the author wrote is never touched.
 */
export function withWebhookField(config: WebhookConfig, key: string, value: string | number | null): WebhookConfig {
    const next: WebhookConfig = { ...config, response_mode: webhookResponseMode(config) }
    const previousDefault = defaultSignedPayload(config.timestamp_header)

    if (value === null || value === '' || (typeof value === 'number' && Number.isNaN(value))) {
        delete next[key]
    } else {
        next[key] = value
    }

    if (key === 'timestamp_header') {
        const followsHeader = next.signed_payload === undefined || next.signed_payload === previousDefault

        if (next.timestamp_header === undefined) {
            delete next.timestamp_tolerance
        }

        if (followsHeader) {
            const signed = defaultSignedPayload(next.timestamp_header)

            if (signed === undefined) {
                delete next.signed_payload
            } else {
                next.signed_payload = signed
            }
        }
    }

    return next
}

/** Switching the verification type drops the keys that only make sense for HMAC. */
export function withWebhookVerification(config: WebhookConfig, verification: string): WebhookConfig {
    const next: WebhookConfig = { ...config, verification, response_mode: webhookResponseMode(config) }

    if (verification !== 'hmac_sha256') {
        delete next.signature_prefix
        delete next.timestamp_header
        delete next.timestamp_tolerance
        delete next.signed_payload
    }

    return next
}
