import { describe, expect, it } from 'vitest'
import { dayLabel, isQuiet, percentOf, scaleMax } from './activity'

const day = (executed: number, failed: number, date = '2026-10-10') => ({ date, executed, failed })

describe('scaleMax', () => {
  it('is 1 for a quiet window, so no bar divides by zero', () => {
    expect(scaleMax([day(0, 0)])).toBe(1)
  })

  it('rounds the busiest stacked day up to a readable step', () => {
    expect(scaleMax([day(3, 4), day(1, 0)])).toBe(10)
    expect(scaleMax([day(120, 0)])).toBe(200)
    expect(scaleMax([day(5, 0)])).toBe(5)
  })
})

describe('percentOf', () => {
  it('scales against the maximum', () => {
    expect(percentOf(5, 10)).toBe(50)
    expect(percentOf(1, 3)).toBe(33.3)
    expect(percentOf(1, 0)).toBe(0)
  })
})

describe('isQuiet', () => {
  it('is true only when nothing ran', () => {
    expect(isQuiet([day(0, 0), day(0, 0)])).toBe(true)
    expect(isQuiet([day(0, 0), day(0, 1)])).toBe(false)
  })
})

describe('dayLabel', () => {
  it('reads the date as the UTC day the server counted', () => {
    expect(dayLabel('2026-10-01', 'en')).toBe('Oct 1')
  })
})
