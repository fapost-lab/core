import {describe, expect, it} from 'vitest'
import {relativeTime} from './relative-time'

const now = new Date('2026-10-09T12:00:00Z')

describe('relativeTime', () => {
    it('reads the past in the largest fitting unit', () => {
        expect(relativeTime('2026-10-09T09:00:00Z', 'en', now)).toBe('3 hours ago')
        expect(relativeTime('2026-10-06T12:00:00Z', 'en', now)).toBe('3 days ago')
        expect(relativeTime('2026-08-01T12:00:00Z', 'en', now)).toBe('2 months ago')
    })

    it('reads the future', () => {
        expect(relativeTime('2026-10-09T14:00:00Z', 'en', now)).toBe('in 2 hours')
    })

    it('says "now" under a minute', () => {
        expect(relativeTime('2026-10-09T11:59:40Z', 'en', now)).toBe('now')
    })

    it('follows the interface language', () => {
        expect(relativeTime('2026-10-06T12:00:00Z', 'ru', now)).toBe('3 дня назад')
    })
})
