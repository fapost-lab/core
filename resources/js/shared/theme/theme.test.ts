import {describe, expect, it} from 'vitest'
import {STORAGE_KEY, applyTheme, isDark, readPreference, writePreference, type ThemeStorage} from './theme'

function memoryStorage(initial: Record<string, string> = {}): ThemeStorage & { data: Record<string, string> } {
    const data = {...initial}

    return {
        data,
        getItem: (key) => data[key] ?? null,
        setItem: (key, value) => {
            data[key] = value
        },
    }
}

function fakeRoot() {
    const classes = new Set<string>()

    return {
        classes,
        classList: {
            toggle: (name: string, force?: boolean) => {
                if (force) {
                    classes.add(name)
                } else {
                    classes.delete(name)
                }

                return Boolean(force)
            },
        },
    } as unknown as Pick<HTMLElement, 'classList'> & { classes: Set<string> }
}

describe('readPreference', () => {
    it('defaults to the system theme', () => {
        expect(readPreference(memoryStorage())).toBe('system')
        expect(readPreference(null)).toBe('system')
    })

    it('returns a stored valid choice', () => {
        for (const choice of ['light', 'dark', 'system'] as const) {
            expect(readPreference(memoryStorage({[STORAGE_KEY]: choice}))).toBe(choice)
        }
    })

    it('ignores a stored value that is not a theme', () => {
        expect(readPreference(memoryStorage({[STORAGE_KEY]: 'sepia'}))).toBe('system')
    })

    it('survives a storage that throws', () => {
        const blocked: ThemeStorage = {
            getItem: () => {
                throw new Error('blocked')
            },
            setItem: () => {
                throw new Error('blocked')
            },
        }

        expect(readPreference(blocked)).toBe('system')
        expect(() => writePreference(blocked, 'dark')).not.toThrow()
    })
})

describe('writePreference', () => {
    it('stores the choice under the key the inline script reads', () => {
        const storage = memoryStorage()

        writePreference(storage, 'dark')

        expect(storage.data[STORAGE_KEY]).toBe('dark')
    })
})

describe('isDark', () => {
    it('follows the system only for the system preference', () => {
        expect(isDark('system', true)).toBe(true)
        expect(isDark('system', false)).toBe(false)
        expect(isDark('light', true)).toBe(false)
        expect(isDark('dark', false)).toBe(true)
    })
})

describe('applyTheme', () => {
    it('adds and removes the dark class', () => {
        const root = fakeRoot()

        expect(applyTheme(root, 'dark', false)).toBe(true)
        expect(root.classes.has('dark')).toBe(true)

        expect(applyTheme(root, 'light', true)).toBe(false)
        expect(root.classes.has('dark')).toBe(false)

        expect(applyTheme(root, 'system', true)).toBe(true)
        expect(root.classes.has('dark')).toBe(true)
    })
})
