/**
 * A moment of the log as a date and a time in the interface language, to the minute: an access log is read for exact
 * times, so no "3 hours ago" here. The ISO string from the server keeps its offset; the browser shows local time.
 */
export function formatDateTime(iso: string, locale: string, timeZone?: string): string {
  return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short', timeZone }).format(new Date(iso))
}
