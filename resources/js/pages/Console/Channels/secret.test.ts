import {describe, expect, it} from 'vitest'
import {generateSecret} from './secret'

describe('generateSecret', () => {
    it('is 48 letters and digits by default', () => {
        expect(generateSecret()).toMatch(/^[A-Za-z0-9]{48}$/)
    })

    it('has the length asked for', () => {
        expect(generateSecret(10)).toHaveLength(10)
    })

    it('differs from one call to the next', () => {
        expect(generateSecret()).not.toBe(generateSecret())
    })

    it('drops the bytes that would favour some characters', () => {
        // 248 and above are not a whole turn of the 62 characters; the first bytes are skipped, the later ones used.
        let call = 0
        const secret = generateSecret(3, (bytes) => {
            call += 1
            bytes.fill(call === 1 ? 255 : 1)
        })

        expect(call).toBe(2)
        expect(secret).toBe('BBB')
    })
})
