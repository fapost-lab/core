import { describe, expect, it } from 'vitest'
import { dayKey, deliveryGlyph, groupByDay, isToday } from './transcript'
import type { Message } from './types'

function message(id: string, createdAt: string): Message {
  return {
    id,
    direction: 'inbound',
    senderType: 'contact',
    author: 'a',
    contentType: 'text',
    text: id,
    media: [],
    buttons: [],
    delivery: null,
    createdAt,
  }
}

describe('groupByDay', () => {
  it('starts a group when the local day changes', () => {
    const groups = groupByDay([
      message('1', '2026-03-01T10:00:00'),
      message('2', '2026-03-01T11:00:00'),
      message('3', '2026-03-02T09:00:00'),
    ])

    expect(groups.map((group) => group.messages.map((m) => m.id))).toEqual([['1', '2'], ['3']])
    expect(groups[0].key).toBe('2026-03-01')
  })

  it('is empty for no messages', () => {
    expect(groupByDay([])).toEqual([])
  })
})

describe('isToday', () => {
  it('compares local days', () => {
    const now = new Date('2026-03-01T12:00:00')

    expect(isToday('2026-03-01T01:00:00', now)).toBe(true)
    expect(isToday('2026-02-28T23:00:00', now)).toBe(false)
    expect(dayKey('2026-03-01T01:00:00')).toBe('2026-03-01')
  })
})

describe('deliveryGlyph', () => {
  it('maps statuses to glyphs', () => {
    expect(deliveryGlyph(null)).toBeNull()
    expect(deliveryGlyph('queued')).toBe('✓')
    expect(deliveryGlyph('sent')).toBe('✓')
    expect(deliveryGlyph('delivered')).toBe('✓✓')
    expect(deliveryGlyph('read')).toBe('✓✓')
    expect(deliveryGlyph('failed')).toBe('✕')
  })
})
