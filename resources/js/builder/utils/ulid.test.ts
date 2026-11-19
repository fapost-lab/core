import { describe, expect, it } from 'vitest'
import { newUlidAsUuid } from './ulid'

describe('newUlidAsUuid', () => {
    it('writes a lower-case RFC 4122 string', () => {
        expect(newUlidAsUuid()).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/)
    })

    it('starts with the 48-bit millisecond timestamp, so ids sort by creation time', () => {
        const now = 1_763_548_800_123

        expect(newUlidAsUuid(now).replace(/-/g, '').slice(0, 12)).toBe(now.toString(16).padStart(12, '0'))
        expect(newUlidAsUuid(now) < newUlidAsUuid(now + 1)).toBe(true)
    })

    it('fills the rest with random bits', () => {
        expect(newUlidAsUuid(0)).not.toBe(newUlidAsUuid(0))
    })
})
