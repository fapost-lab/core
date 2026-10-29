import { describe, expect, it } from 'vitest'
import { formatUtc } from './utc'

describe('formatUtc', () => {
  it('writes the time in UTC and says so', () => {
    expect(formatUtc('2026-10-29T21:11:09Z', 'en')).toContain('UTC')
    expect(formatUtc('2026-10-29T21:11:09Z', 'en')).toMatch(/9:11/)
  })

  it('leaves an unreadable value as it is', () => {
    expect(formatUtc('not a time', 'en')).toBe('not a time')
  })
})
