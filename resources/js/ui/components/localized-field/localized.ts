/** The text of one language. A list of them keeps the order of the tabs. */
export interface LocalizedEntry {
  locale: string
  text: string
}

/** One tab of a localized field; a `required` language is marked with the field's `requiredLabel`. */
export interface LocalizedLanguage {
  code: string
  required?: boolean
}

/** The locales whose text is not blank, so a tab can show it is filled in. */
export function filledLocales(entries: LocalizedEntry[]): Set<string> {
  return new Set(entries.filter((entry) => entry.text.trim() !== "").map((entry) => entry.locale))
}

/**
 * What a form sends: the locales that have text, as `{locale: text}`. A blank text is left out; the text kept is not
 * trimmed.
 */
export function localizedRecord(entries: LocalizedEntry[]): Record<string, string> {
  const record: Record<string, string> = {}

  for (const { locale, text } of entries) {
    if (text.trim() !== "") {
      record[locale] = text
    }
  }

  return record
}

/** A form's errors under one field, keyed by locale (`message.ru` under `message` gives `ru`), for a localized field. */
export function errorsByLocale(errors: Record<string, string | undefined>, field: string): Record<string, string | undefined> {
  const own: Record<string, string | undefined> = {}

  for (const [key, message] of Object.entries(errors)) {
    if (key.startsWith(`${field}.`)) {
      own[key.slice(field.length + 1)] = message
    }
  }

  return own
}

/** The text of a locale, or an empty one when it has none yet. */
export function textOf(entries: LocalizedEntry[], locale: string): string {
  return entries.find((entry) => entry.locale === locale)?.text ?? ""
}

/** Sets the text of a locale without changing the list it was given; a locale it lacks is added at the end. */
export function setLocalizedText(entries: LocalizedEntry[], locale: string, text: string): LocalizedEntry[] {
  return entries.some((entry) => entry.locale === locale)
    ? entries.map((entry) => (entry.locale === locale ? { ...entry, text } : entry))
    : [...entries, { locale, text }]
}

/** The locales with an error, in the order of the tabs. */
export function localesWithErrors(errors: Record<string, string | undefined>, locales: string[]): string[] {
  return locales.filter((locale) => errors[locale] !== undefined)
}

/**
 * The tab to open after a submit: the current one when it has an error, otherwise the first one with an error in
 * the order of the tabs, so an error is never left hidden. `null` when the current tab may stay.
 */
export function tabToReveal(errors: Record<string, string | undefined>, locales: string[], current: string): string | null {
  const withErrors = localesWithErrors(errors, locales)

  return withErrors.length === 0 || withErrors.includes(current) ? null : withErrors[0]
}
