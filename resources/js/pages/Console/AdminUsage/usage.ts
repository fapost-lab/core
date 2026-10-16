import { interpolate } from '@console/shell'
import type { UsageKind, UsageRowData, UsageState, UsageTranslations } from './types'

const KIND_ORDER: UsageKind[] = ['records', 'per_period', 'bytes']

/** The rows of each kind in the order the page lists them; a kind without rows is left out. */
export function groupByKind(rows: UsageRowData[]): { kind: UsageKind; rows: UsageRowData[] }[] {
  return KIND_ORDER.map((kind) => ({ kind, rows: rows.filter((row) => row.kind === kind) })).filter((group) => group.rows.length > 0)
}

/** The end of the period when every row of the group has the same one, else null (each row then shows its own). */
export function sharedPeriodEnd(rows: UsageRowData[]): string | null {
  const ends = new Set(rows.map((row) => row.periodEndsLabel))

  return ends.size === 1 ? (rows[0]?.periodEndsLabel ?? null) : null
}

/** Whether the row has a bar: it needs a limit and a count, and is not unknown. */
export function hasBar(row: UsageRowData): boolean {
  return row.limit !== null && row.used !== null && (row.state === 'ok' || row.state === 'near' || row.state === 'reached')
}

export type BarTone = 'primary' | 'warning' | 'danger'

export function barTone(state: UsageState): BarTone {
  if (state === 'reached') {
    return 'danger'
  }

  return state === 'near' ? 'warning' : 'primary'
}

export type BadgeVariant = 'neutral' | 'warning' | 'danger'

/** The badge under a row, or null for a row that is simply fine. */
export function badgeFor(state: UsageState): { variant: BadgeVariant; text: keyof UsageTranslations['state'] } | null {
  switch (state) {
    case 'unlimited':
      return { variant: 'neutral', text: 'unlimited' }
    case 'not_reported':
      return { variant: 'neutral', text: 'not_reported' }
    case 'unavailable':
      return { variant: 'neutral', text: 'unavailable' }
    case 'near':
      return { variant: 'warning', text: 'near' }
    case 'reached':
      return { variant: 'danger', text: 'reached' }
    default:
      return null
  }
}

/** "3 of 5", "Limit: 5" when the use is unknown, the use alone when unlimited, a dash when nothing is known. */
export function valueText(row: UsageRowData, t: Pick<UsageTranslations, 'of' | 'only'>): string {
  switch (row.state) {
    case 'unavailable':
      return '—'
    case 'unlimited':
      return row.usedText ?? '—'
    case 'not_reported':
      return interpolate(t.only, { limit: row.limitText ?? '—' })
    default:
      return interpolate(t.of, { used: row.usedText ?? '—', limit: row.limitText ?? '—' })
  }
}
