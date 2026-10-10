import { describe, expect, it } from 'vitest'
import { formatDateTime } from './datetime'

describe('formatDateTime', () => {
  it('shows the date and the time to the minute in the interface language', () => {
    const shown = formatDateTime('2026-10-07T09:05:30+00:00', 'en', 'UTC')

    expect(shown).toContain('2026')
    expect(shown).toContain('9:05')
    expect(shown).not.toContain(':30')
  })

  it('follows the locale', () => {
    expect(formatDateTime('2026-10-07T09:05:00+00:00', 'ru', 'UTC')).toContain('окт')
  })
})
