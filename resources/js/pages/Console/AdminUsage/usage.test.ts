import { describe, expect, it } from 'vitest'
import type { UsageRowData } from './types'
import { badgeFor, barTone, groupByKind, hasBar, sharedPeriodEnd, valueText } from './usage'

function row(over: Partial<UsageRowData>): UsageRowData {
  return {
    key: 'assistants',
    label: 'Assistants',
    kind: 'records',
    state: 'ok',
    used: 3,
    limit: 5,
    usedText: '3',
    limitText: '5',
    percent: 60,
    periodEndsAt: null,
    periodEndsLabel: null,
    ...over,
  }
}

const t = { of: ':used of :limit', only: 'Limit: :limit' }

describe('usage rows', () => {
  it('groups rows by kind in page order and leaves out empty kinds', () => {
    const groups = groupByKind([row({ key: 'b', kind: 'bytes' }), row({ key: 'a' }), row({ key: 'c', kind: 'bytes' })])

    expect(groups.map((group) => group.kind)).toEqual(['records', 'bytes'])
    expect(groups[1].rows.map((r) => r.key)).toEqual(['b', 'c'])
  })

  it('folds equal period ends into one and keeps differing ones apart', () => {
    const a = row({ kind: 'per_period', periodEndsLabel: 'November 1, 2026' })

    expect(sharedPeriodEnd([a, { ...a, key: 'x' }])).toBe('November 1, 2026')
    expect(sharedPeriodEnd([a, { ...a, key: 'x', periodEndsLabel: 'October 17, 2026' }])).toBeNull()
  })

  it('draws a bar only for a row that has a limit and a count', () => {
    expect(hasBar(row({}))).toBe(true)
    expect(hasBar(row({ state: 'reached', used: 9 }))).toBe(true)
    expect(hasBar(row({ state: 'unlimited', limit: null, percent: null }))).toBe(false)
    expect(hasBar(row({ state: 'not_reported', used: null, percent: null }))).toBe(false)
    expect(hasBar(row({ state: 'unavailable' }))).toBe(false)
  })

  it('maps the state to a tone and a badge', () => {
    expect(barTone('ok')).toBe('primary')
    expect(barTone('near')).toBe('warning')
    expect(barTone('reached')).toBe('danger')
    expect(badgeFor('ok')).toBeNull()
    expect(badgeFor('near')).toEqual({ variant: 'warning', text: 'near' })
    expect(badgeFor('reached')).toEqual({ variant: 'danger', text: 'reached' })
    expect(badgeFor('unlimited')?.variant).toBe('neutral')
  })

  it('writes the value of each state', () => {
    expect(valueText(row({}), t)).toBe('3 of 5')
    expect(valueText(row({ state: 'unlimited', limit: null, limitText: null }), t)).toBe('3')
    expect(valueText(row({ state: 'not_reported', used: null, usedText: null }), t)).toBe('Limit: 5')
    expect(valueText(row({ state: 'unavailable' }), t)).toBe('—')
  })
})
