import type { Message } from './types'

export interface DayGroup {
  key: string
  /** The first message's timestamp, for formatting the separator. */
  date: string
  messages: Message[]
}

/** The local calendar day of a timestamp, as `YYYY-MM-DD`. */
export function dayKey(iso: string): string {
  const date = new Date(iso)
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${date.getFullYear()}-${month}-${day}`
}

/** Messages in the order given, split into runs of the same local day. */
export function groupByDay(messages: Message[]): DayGroup[] {
  const groups: DayGroup[] = []

  for (const message of messages) {
    const key = dayKey(message.createdAt)
    const last = groups[groups.length - 1]

    if (last && last.key === key) {
      last.messages.push(message)
    } else {
      groups.push({ key, date: message.createdAt, messages: [message] })
    }
  }

  return groups
}

export function isToday(iso: string, now: Date = new Date()): boolean {
  return dayKey(iso) === dayKey(now.toISOString())
}

/** A delivery status as the glyph next to the time of an outbound message. */
export function deliveryGlyph(status: string | null): string | null {
  switch (status) {
    case null:
      return null
    case 'failed':
      return '✕'
    case 'delivered':
    case 'read':
      return '✓✓'
    case 'received':
    case 'queued':
    case 'sent':
      return '✓'
    default:
      return '✓'
  }
}

/** The time of day of a timestamp, `HH:MM` in the viewer's locale. */
export function timeLabel(iso: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit' }).format(new Date(iso))
}
