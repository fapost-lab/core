import {describe, expect, it} from 'vitest'
import {isTranslatableField} from './translatableFields'

describe('isTranslatableField', () => {
    it('knows the translatable top-level fields', () => {
        expect(isTranslatableField('send_message', 'text')).toBe(true)
        expect(isTranslatableField('send_message', 'caption')).toBe(true)
        expect(isTranslatableField('input', 'prompt')).toBe(true)
        expect(isTranslatableField('input', 'on_invalid_message')).toBe(true)
    })

    it('rejects everything else', () => {
        expect(isTranslatableField('send_message', 'buttons')).toBe(false)
        expect(isTranslatableField('input', 'text')).toBe(false)
        expect(isTranslatableField('assign', 'text')).toBe(false)
    })
})
