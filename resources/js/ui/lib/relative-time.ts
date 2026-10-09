const UNITS: ReadonlyArray<[Intl.RelativeTimeFormatUnit, number]> = [
    ['year', 365 * 24 * 3600],
    ['month', 30 * 24 * 3600],
    ['week', 7 * 24 * 3600],
    ['day', 24 * 3600],
    ['hour', 3600],
    ['minute', 60],
]

/**
 * "3 days ago", "in 2 hours": a moment relative to `now`, in the interface language. The unit is the largest that
 * fits; under a minute it reads "now" (or the language's own word for it).
 */
export function relativeTime(iso: string, locale: string, now: Date = new Date()): string {
    const formatter = new Intl.RelativeTimeFormat(locale, {numeric: 'auto'})
    const seconds = Math.round((new Date(iso).getTime() - now.getTime()) / 1000)

    for (const [unit, size] of UNITS) {
        if (Math.abs(seconds) >= size) {
            return formatter.format(Math.trunc(seconds / size), unit)
        }
    }

    return formatter.format(0, 'second')
}
