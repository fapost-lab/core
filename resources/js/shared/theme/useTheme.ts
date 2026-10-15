import {onBeforeUnmount, onMounted, ref, type Ref} from 'vue'
import {applyTheme, readPreference, writePreference, type ThemePreference} from './theme'

const preference = ref<ThemePreference>('system')
let initialised = false

function storage(): Storage | null {
    try {
        return window.localStorage
    } catch {
        return null
    }
}

function systemPrefersDark(): boolean {
    return window.matchMedia('(prefers-color-scheme: dark)').matches
}

function apply(): void {
    applyTheme(document.documentElement, preference.value, systemPrefersDark())
}

/**
 * The console's theme: the current preference, a setter, and the follow-the-system listener.
 * The preference is shared by every caller, so a layout and a menu see the same value.
 */
export function useTheme(): { preference: Ref<ThemePreference>; setPreference: (value: ThemePreference) => void } {
    let query: MediaQueryList | null = null

    const onSystemChange = (): void => {
        if (preference.value === 'system') {
            apply()
        }
    }

    onMounted(() => {
        if (!initialised) {
            initialised = true
            preference.value = readPreference(storage())
        }

        apply()

        query = window.matchMedia('(prefers-color-scheme: dark)')
        query.addEventListener('change', onSystemChange)
    })

    onBeforeUnmount(() => query?.removeEventListener('change', onSystemChange))

    function setPreference(value: ThemePreference): void {
        preference.value = value
        writePreference(storage(), value)
        apply()
    }

    return {preference, setPreference}
}
