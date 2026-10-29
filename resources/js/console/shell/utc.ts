/**
 * An ISO 8601 time written out in the reader's language and labelled UTC: Core keeps no time zone per workspace, so a
 * time the server announces ("deletion starts at") is stated in UTC rather than guessed in the browser's zone.
 */
export function formatUtc(iso: string, locale: string): string {
  const date = new Date(iso)

  if (Number.isNaN(date.getTime())) {
    return iso
  }

  return `${date.toLocaleString(locale, { timeZone: 'UTC', dateStyle: 'medium', timeStyle: 'short' })} UTC`
}
