import { describe, expect, it } from 'vitest'
import {
    defaultWebhookConfig,
    isLegacyWebhookConfig,
    webhookConfigFromPreset,
    withWebhookField,
    withWebhookVerification,
} from './webhookTrigger'

describe('webhook presets', () => {
    it('defaults to the generic HMAC preset', () => {
        expect(defaultWebhookConfig()).toEqual({
            verification: 'hmac_sha256',
            signature_header: 'X-Signature',
            signature_prefix: 'sha256=',
            response_mode: 'async',
        })
    })

    it('maps the GitHub preset with a delivery id', () => {
        expect(webhookConfigFromPreset('github')).toMatchObject({
            signature_header: 'X-Hub-Signature-256',
            delivery_id: '{header.X-GitHub-Delivery}',
        })
    })

    it('maps the secret-header preset without HMAC keys', () => {
        const config = webhookConfigFromPreset('header')

        expect(config.verification).toBe('header_equals')
        expect(config).not.toHaveProperty('signature_prefix')
    })

    it('returns a fresh object each time', () => {
        expect(webhookConfigFromPreset('hmac')).not.toBe(webhookConfigFromPreset('hmac'))
    })
})

describe('legacy detection', () => {
    it('flags the old method/path/secret shape', () => {
        expect(isLegacyWebhookConfig({ method: 'POST', path: '', secret: '' })).toBe(true)
        expect(isLegacyWebhookConfig({})).toBe(true)
    })

    it('accepts the new shape', () => {
        expect(isLegacyWebhookConfig(defaultWebhookConfig())).toBe(false)
    })
})

describe('field editing', () => {
    it('omits empty optional values', () => {
        const config = withWebhookField(defaultWebhookConfig(), 'signature_prefix', '')

        expect(config).not.toHaveProperty('signature_prefix')
    })

    it('sets a value', () => {
        expect(withWebhookField(defaultWebhookConfig(), 'timestamp_tolerance', 120).timestamp_tolerance).toBe(120)
    })

    it('drops HMAC-only keys when switching to a header secret', () => {
        const config = withWebhookVerification(
            { ...defaultWebhookConfig(), timestamp_header: 'X-Ts', timestamp_tolerance: 60 },
            'header_equals',
        )

        expect(config).toEqual({ verification: 'header_equals', signature_header: 'X-Signature', response_mode: 'async' })
    })

    it('prefills the signed string when a timestamp header is set', () => {
        const config = withWebhookField(defaultWebhookConfig(), 'timestamp_header', 'X-Ts')

        expect(config.signed_payload).toBe('{header.X-Ts}.{raw}')
    })

    it('keeps an existing signed string when the timestamp header is set', () => {
        const config = withWebhookField({ ...defaultWebhookConfig(), signed_payload: 'v0:{raw}' }, 'timestamp_header', 'X-Ts')

        expect(config.signed_payload).toBe('v0:{raw}')
    })

    it('clears the tolerance when the timestamp header is cleared', () => {
        const config = withWebhookField({ ...webhookConfigFromPreset('hmac_timestamp'), timestamp_tolerance: 60 }, 'timestamp_header', '')

        expect(config).not.toHaveProperty('timestamp_header')
        expect(config).not.toHaveProperty('timestamp_tolerance')
    })

    it('maps the timestamp preset with a signed string covering the header', () => {
        expect(webhookConfigFromPreset('hmac_timestamp').signed_payload).toBe('{header.X-Timestamp}.{raw}')
    })
})
