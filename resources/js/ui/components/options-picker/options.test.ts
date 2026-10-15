import { describe, expect, it } from 'vitest'
import { chipsOf, filterOptions, normalizeOptions, toggleValue } from './options'

const countries = [
  { value: 'UA', label: 'Ukraine' },
  { value: 'GB', label: 'United Kingdom' },
  { value: 'DE', label: 'Germany' },
]

describe('normalizeOptions', () => {
  it('makes a plain string its own label', () => {
    expect(normalizeOptions(['vip', { value: 'a', label: 'A' }])).toEqual([
      { value: 'vip', label: 'vip' },
      { value: 'a', label: 'A' },
    ])
  })
})

describe('filterOptions', () => {
  it('keeps all for a blank query', () => {
    expect(filterOptions(countries, '  ')).toEqual(countries)
  })

  it('matches a part of the label, ignoring case', () => {
    expect(filterOptions(countries, 'uNi').map((option) => option.value)).toEqual(['GB'])
  })

  it('matches a whole value', () => {
    expect(filterOptions(countries, 'de').map((option) => option.value)).toEqual(['DE'])
  })
})

describe('chipsOf', () => {
  it('labels the chosen values in the order chosen', () => {
    expect(chipsOf(['DE', 'UA'], countries)).toEqual([
      { value: 'DE', label: 'Germany', missing: false },
      { value: 'UA', label: 'Ukraine', missing: false },
    ])
  })

  it('marks a value the list lacks, named by the missing label or by itself', () => {
    expect(chipsOf(['XX'], countries, 'Deleted')).toEqual([{ value: 'XX', label: 'Deleted', missing: true }])
    expect(chipsOf(['XX'], countries)).toEqual([{ value: 'XX', label: 'XX', missing: true }])
  })
})

describe('toggleValue', () => {
  it('adds a ticked value last and never twice', () => {
    expect(toggleValue(['a'], 'b', true)).toEqual(['a', 'b'])
    expect(toggleValue(['a', 'b'], 'a', true)).toEqual(['b', 'a'])
  })

  it('removes an unticked value', () => {
    expect(toggleValue(['a', 'b'], 'a', false)).toEqual(['b'])
  })
})
