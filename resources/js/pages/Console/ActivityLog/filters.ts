/** The window the server applies when the request names none; the screen shows it as selected. */
export const DEFAULT_PERIOD = '30'

/**
 * Whether the list is narrowed by anything the user can reset: a search, or a filter other than the default window.
 * The default period alone is not a narrowing, so an empty log offers no "reset" that changes nothing.
 */
export function isNarrowed(state: { search: string; filters?: Record<string, string> }): boolean {
  if (state.search !== '') {
    return true
  }

  return Object.entries(state.filters ?? {}).some(([key, value]) => !(key === 'period' && value === DEFAULT_PERIOD))
}

/** A change's value for the sheet: `null` is an empty value, shown as the given word. */
export function shownValue(value: string | null, empty: string): string {
  return value === null || value === '' ? empty : value
}
