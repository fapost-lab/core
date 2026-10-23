import type { HealthState } from './types'

export type StatusTone = 'success' | 'danger' | 'warning' | 'neutral'
export type BadgeVariant = 'success' | 'danger' | 'warning' | 'neutral'

/**
 * How a state is coloured. Nothing is green but a service that answered "up": loading, unknown and a failed request
 * are never drawn as healthy, and "switched off" is neutral because it is a choice, not a fault.
 */
export function toneFor(state: HealthState | 'loading'): StatusTone {
  switch (state) {
    case 'up':
      return 'success'
    case 'down':
      return 'danger'
    case 'unknown':
      return 'warning'
    default:
      return 'neutral'
  }
}

/** The local time of a probe's answer, or null when it has none. */
export function formatChecked(iso: string | null, locale: string): string | null {
  if (iso === null) {
    return null
  }

  const date = new Date(iso)

  return Number.isNaN(date.getTime()) ? null : date.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

/** The newest answer among the cards, which is when the page's figures are from. */
export function newestCheck(times: (string | null)[]): string | null {
  const valid = times.filter((time): time is string => time !== null && !Number.isNaN(new Date(time).getTime()))

  return valid.length === 0 ? null : valid.reduce((a, b) => (new Date(a) >= new Date(b) ? a : b))
}
