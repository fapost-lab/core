import { ref } from 'vue'
import { fetchTriggerTypes } from '@builder/api/builderApi'
import type { TriggerTypeEntry } from '@builder/api/builderApi'

const triggerTypes = ref<TriggerTypeEntry[]>([])
const loaded = ref(false)
let pending: Promise<void> | null = null

/**
 * The trigger types the editor offers, loaded once per page. A failed request leaves the list empty
 * (and `loaded` false), so the editor falls back to its built-in types without judging unknown ones.
 */
export function useTriggerTypes() {
    function load(): Promise<void> {
        if (loaded.value) {
            return Promise.resolve()
        }

        pending ??= fetchTriggerTypes()
            .then((response) => {
                triggerTypes.value = response.data
                loaded.value = true
            })
            .catch(() => undefined)
            .finally(() => {
                pending = null
            })

        return pending
    }

    function find(type: string): TriggerTypeEntry | undefined {
        return triggerTypes.value.find((entry) => entry.type === type)
    }

    return { triggerTypes, loaded, load, find }
}

/** Forgets the loaded list; for tests. */
export function resetTriggerTypes(): void {
    triggerTypes.value = []
    loaded.value = false
    pending = null
}
