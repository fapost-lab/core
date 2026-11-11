import { describe, expect, it } from 'vitest'
import { isNarrowed, shownValue } from './filters'

describe('isNarrowed', () => {
  it('is false for the default window alone', () => {
    expect(isNarrowed({ search: '', filters: { period: '30' } })).toBe(false)
    expect(isNarrowed({ search: '' })).toBe(false)
  })

  it('is true for a search, another period or any other filter', () => {
    expect(isNarrowed({ search: 'anna', filters: { period: '30' } })).toBe(true)
    expect(isNarrowed({ search: '', filters: { period: '7' } })).toBe(true)
    expect(isNarrowed({ search: '', filters: { period: '30', area: 'flows' } })).toBe(true)
  })
})

describe('shownValue', () => {
  it('shows the empty word for null and the empty string', () => {
    expect(shownValue(null, '—')).toBe('—')
    expect(shownValue('', '—')).toBe('—')
  })

  it('keeps a value as it is', () => {
    expect(shownValue('0', '—')).toBe('0')
    expect(shownValue('Helper', '—')).toBe('Helper')
  })
})
