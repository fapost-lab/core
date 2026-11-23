import {describe, expect, it} from 'vitest'
import {isTranslatableField, translatableFieldsOf} from './translatableFields'

describe('isTranslatableField', () => {
    it('knows the translatable top-level fields', () => {
        expect(isTranslatableField('send_message', 'text')).toBe(true)
        expect(isTranslatableField('send_message', 'caption')).toBe(true)
        expect(isTranslatableField('input', 'prompt')).toBe(true)
        expect(isTranslatableField('input', 'on_invalid_message')).toBe(true)
    })

    it('knows the auth_request contact-facing fields', () => {
        for (const field of ['prompt', 'button_label', 'resend_label', 'on_invalid_message']) {
            expect(isTranslatableField('auth_request', field)).toBe(true)
        }
        expect(isTranslatableField('auth_request', 'destination')).toBe(false)
        expect(translatableFieldsOf('auth_request').map((d) => d.field)).toEqual([
            'prompt', 'button_label', 'resend_label', 'on_invalid_message',
        ])
    })

    it('lists an auth_request field only once it is filled', () => {
        const prompt = translatableFieldsOf('auth_request')[0]
        expect(prompt.shown({})).toBe(false)
        expect(prompt.shown({prompt: {en: 'Hi'}})).toBe(true)
    })

    it('rejects everything else', () => {
        expect(isTranslatableField('send_message', 'buttons')).toBe(false)
        expect(isTranslatableField('input', 'text')).toBe(false)
        expect(isTranslatableField('assign', 'text')).toBe(false)
    })
})
