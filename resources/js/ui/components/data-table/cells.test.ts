import { describe, expect, it } from 'vitest'
import { formatCell } from './cells'

const now = new Date('2026-10-14T12:00:00Z')

describe('formatting a cell by its type', () => {
  it('leaves a missing value empty whatever the type', () => {
    for (const type of ['text', 'badge', 'number', 'date', 'status']) {
      expect(formatCell(type, null, { locale: 'en' }).kind).toBe('empty')
      expect(formatCell(type, undefined, { locale: 'en' }).kind).toBe('empty')
      expect(formatCell(type, '', { locale: 'en' }).kind).toBe('empty')
    }
  })

  it('shows text as it came', () => {
    expect(formatCell('text', 'Kyiv', { locale: 'en' })).toEqual({ kind: 'text', text: 'Kyiv', tone: 'neutral', numeric: false })
    expect(formatCell('text', 0, { locale: 'en' }).text).toBe('0')
  })

  it('formats a number in the interface language', () => {
    expect(formatCell('number', 1234.5, { locale: 'en' })).toMatchObject({ text: '1,234.5', numeric: true })
    expect(formatCell('number', '1234.5', { locale: 'de' })).toMatchObject({ text: '1.234,5', numeric: true })
  })

  it('shows a value a number column cannot read as it came', () => {
    expect(formatCell('number', 'many', { locale: 'en' })).toMatchObject({ kind: 'text', text: 'many', numeric: false })
    expect(formatCell('number', true, { locale: 'en' })).toMatchObject({ text: 'true', numeric: false })
  })

  it('shows a date relative to now, with the moment on hover', () => {
    expect(formatCell('date', '2026-10-11T12:00:00Z', { locale: 'en', now })).toMatchObject({ kind: 'text', text: '3 days ago', title: '2026-10-11T12:00:00Z' })
    expect(formatCell('date', 'someday', { locale: 'en', now })).toEqual({ kind: 'text', text: 'someday', tone: 'neutral', numeric: false })
  })

  it('colours a badge and a status by the column tones, neutral otherwise', () => {
    const tones = { happy: 'success', angry: 'danger' } as const

    expect(formatCell('status', 'happy', { locale: 'en', tones })).toMatchObject({ kind: 'status', text: 'happy', tone: 'success' })
    expect(formatCell('badge', 'angry', { locale: 'en', tones })).toMatchObject({ kind: 'badge', tone: 'danger' })
    expect(formatCell('status', 'calm', { locale: 'en', tones })).toMatchObject({ kind: 'status', tone: 'neutral' })
    expect(formatCell('status', 'odd', { locale: 'en', tones: { odd: 'purple' as never } }).tone).toBe('neutral')
  })

  it('reads an unknown type as text', () => {
    expect(formatCell('sparkline', 'x', { locale: 'en' })).toMatchObject({ kind: 'text', text: 'x' })
  })
})
