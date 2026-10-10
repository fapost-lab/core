import type { ActivityDay } from './types'

/** The top of the chart's scale: the busiest day's total, rounded up to a readable step (never below 1). */
export function scaleMax(days: ActivityDay[]): number {
  const busiest = days.reduce((max, day) => Math.max(max, day.executed + day.failed), 0)

  if (busiest <= 0) {
    return 1
  }

  const magnitude = 10 ** Math.floor(Math.log10(busiest))

  return [1, 2, 5, 10].map((factor) => factor * magnitude).find((candidate) => candidate >= busiest) ?? 10 * magnitude
}

/** A bar's height as a percentage of the scale. */
export function percentOf(value: number, max: number): number {
  return max <= 0 ? 0 : Math.round((value / max) * 1000) / 10
}

/** Whether no node ran on any day of the window. */
export function isQuiet(days: ActivityDay[]): boolean {
  return days.every((day) => day.executed === 0 && day.failed === 0)
}

/** A day as the chart labels it (`10 Oct`), read as the UTC day the server counted. */
export function dayLabel(date: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`))
}
