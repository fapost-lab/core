/** One hit of the admin search, as `AdminSearch` returns it. */
export interface SearchHit {
  id: string
  title: string
  subtitle: string | null
  url: string
  /** The screen is still Filament's: a full page load, never an Inertia visit. */
  external: boolean
}

export interface SearchGroup {
  key: 'assistants' | 'users' | 'roles' | 'media'
  items: SearchHit[]
}

/** The shortest text the server searches (`AdminSearch::MIN_LENGTH`). */
export const SEARCH_MIN_LENGTH = 2

/** How long the palette waits after the last keystroke before it asks. */
export const SEARCH_DEBOUNCE_MS = 250

/** The text as it is sent, or `null` when it is too short to ask about. */
export function searchable(text: string): string | null {
  const trimmed = text.trim()

  return [...trimmed].length >= SEARCH_MIN_LENGTH ? trimmed : null
}

/** Whether the key event is the palette's shortcut: ⌘K on a Mac, Ctrl+K elsewhere. */
export function isPaletteShortcut(event: Pick<KeyboardEvent, 'key' | 'metaKey' | 'ctrlKey' | 'altKey' | 'shiftKey'>): boolean {
  return event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey) && !event.altKey && !event.shiftKey
}

/** A stable value for a hit in the list box: the same id may appear in two groups. */
export function hitValue(group: SearchGroup, hit: SearchHit): string {
  return `${group.key}:${hit.id}`
}
