/**
 * Colour theme of the console: light, dark, or whatever the system prefers.
 *
 * The choice lives in localStorage and shows as the `.dark` class on <html>. The same rule runs twice: in the
 * inline script of resources/views/partials/theme-script.blade.php (included by the console and builder views) before the first paint (so a dark page never flashes light), and here.
 * Keep `STORAGE_KEY` equal to the key that script reads.
 */
export type ThemePreference = 'light' | 'dark' | 'system'

export const STORAGE_KEY = 'fapost-theme'

export const THEME_PREFERENCES: readonly ThemePreference[] = ['light', 'dark', 'system']

/** The minimal slice of `Storage` the theme needs, so tests do not need a browser. */
export interface ThemeStorage {
    getItem(key: string): string | null
    setItem(key: string, value: string): void
}

export function isThemePreference(value: unknown): value is ThemePreference {
    return typeof value === 'string' && (THEME_PREFERENCES as readonly string[]).includes(value)
}

/** The stored preference; `system` when nothing valid is stored or storage is unavailable. */
export function readPreference(storage: ThemeStorage | null): ThemePreference {
    try {
        const stored = storage?.getItem(STORAGE_KEY)

        return isThemePreference(stored) ? stored : 'system'
    } catch {
        return 'system'
    }
}

/** Remembers the preference. Storage can be blocked (private windows), and then the choice only lasts the page. */
export function writePreference(storage: ThemeStorage | null, preference: ThemePreference): void {
    try {
        storage?.setItem(STORAGE_KEY, preference)
    } catch {
        // Not remembered; the theme is still applied to the page.
    }
}

/** Whether the page should be dark for a preference and the system's current setting. */
export function isDark(preference: ThemePreference, systemPrefersDark: boolean): boolean {
    return preference === 'system' ? systemPrefersDark : preference === 'dark'
}

/** Puts the `.dark` class on the element (normally <html>) as the preference and the system setting say. */
export function applyTheme(root: Pick<HTMLElement, 'classList'>, preference: ThemePreference, systemPrefersDark: boolean): boolean {
    const dark = isDark(preference, systemPrefersDark)

    root.classList.toggle('dark', dark)

    return dark
}
