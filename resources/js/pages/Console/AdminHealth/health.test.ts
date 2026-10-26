import { describe, expect, it } from 'vitest'
import { formatChecked, formatSince, newestCheck, toneFor } from './health'

describe('toneFor', () => {
  it('is green only for a service that answered up', () => {
    expect(toneFor('up')).toBe('success')
    expect(toneFor('down')).toBe('danger')
    expect(toneFor('unknown')).toBe('warning')
    expect(toneFor('disabled')).toBe('neutral')
    expect(toneFor('loading')).toBe('neutral')
  })
})

describe('checked times', () => {
  it('has no time for a service without an answer or a broken date', () => {
    expect(formatChecked(null, 'en')).toBeNull()
    expect(formatChecked('not a date', 'en')).toBeNull()
    expect(formatChecked('2026-10-22T10:00:05Z', 'en')).toMatch(/\d/)
  })

  it('takes the newest answer among the cards', () => {
    expect(newestCheck(['2026-10-22T10:00:00Z', null, '2026-10-22T10:00:09Z', 'junk'])).toBe('2026-10-22T10:00:09Z')
    expect(newestCheck([null, null])).toBeNull()
  })
})

describe('formatSince', () => {
  const now = new Date(2026, 9, 26, 12, 0, 0)

  it('shows only the time for a window that began today', () => {
    expect(formatSince(new Date(2026, 9, 26, 11, 55).toISOString(), 'en', now)).not.toMatch(/Oct/)
  })

  it('adds the date to a window that began on another day', () => {
    expect(formatSince(new Date(2026, 9, 21, 11, 55).toISOString(), 'en', now)).toMatch(/Oct/)
  })

  it('has nothing to show without a valid time', () => {
    expect(formatSince(null, 'en', now)).toBeNull()
    expect(formatSince('nonsense', 'en', now)).toBeNull()
  })
})
