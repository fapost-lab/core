/**
 * Helpers for tests that mount a Core screen with Solution extensions (happy-dom). Not imported by the application.
 */
import type { ConsoleExtensions } from './extensions'

/** Every translation reads as its own path, so a screen renders without the lang files. */
export function translationPaths(path: string): unknown {
  return new Proxy(() => path, {
    get: (_target, key) => {
      if (key === Symbol.toPrimitive || key === 'toString' || key === 'valueOf') {
        return () => path
      }

      if (typeof key === 'string' && key in String.prototype) {
        const member = (String.prototype as unknown as Record<string, unknown>)[key]

        return typeof member === 'function' ? (member as (...args: unknown[]) => unknown).bind(path) : path.length
      }

      return translationPaths(`${path}.${String(key)}`)
    },
  })
}

/** The shared prop `extensions` with every target empty, then the given ones filled. */
export function extensionsWith(fill: { [G in keyof ConsoleExtensions]?: Partial<ConsoleExtensions[G]> } = {}): ConsoleExtensions {
  const empty: ConsoleExtensions = {
    shell: { floating: [], header: [] },
    actions: { 'contact.view': [], 'contact.row': [], 'conversation.view': [] },
    widgets: { console: [], admin: [] },
    tabs: { assistant: [], tenant: [] },
    columns: { contacts: [], conversations: [] },
  }

  return {
    shell: { ...empty.shell, ...fill.shell },
    actions: { ...empty.actions, ...fill.actions },
    widgets: { ...empty.widgets, ...fill.widgets },
    tabs: { ...empty.tabs, ...fill.tabs },
    columns: { ...empty.columns, ...fill.columns },
  }
}
