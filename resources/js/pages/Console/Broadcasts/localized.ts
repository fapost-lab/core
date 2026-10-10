/** One language of a broadcast's message, in the order of the tabs the server chose (the base language first). */
export interface MessageEntry {
  locale: string
  text: string
}

/**
 * What is sent: the languages that have text, as `{locale: text}`. A blank text is left out, the way the server stores
 * it; the base language being among the rest is the server's check.
 */
export function cleanMessage(entries: MessageEntry[]): Record<string, string> {
  const message: Record<string, string> = {}

  for (const { locale, text } of entries) {
    if (text.trim() !== '') {
      message[locale] = text
    }
  }

  return message
}

/** The locales whose tabs have text, so a tab can show it is filled in. */
export function filledLocales(entries: MessageEntry[]): Set<string> {
  return new Set(entries.filter((entry) => entry.text.trim() !== '').map((entry) => entry.locale))
}

/**
 * The locales a validation error points at (`message.ru`, from the keys of the form's errors), in the order of the
 * tabs, so the form can open the first tab with an error instead of leaving it hidden.
 */
export function localesWithErrors(errors: Record<string, string | undefined>, locales: string[]): string[] {
  return locales.filter((locale) => errors[`message.${locale}`] !== undefined)
}
