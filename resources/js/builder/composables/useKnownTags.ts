import {computed, type ComputedRef, ref} from 'vue'
import {useBuilderStore} from '@builder/store/builderStore'
import {fetchKnownTags} from '@builder/api/builderApi'
import type {FlowNode} from '@builder/dto/types'

// Tenant tag vocabulary fetched once per builder session. Module-level so every
// set_tag node shares a single request and stays in sync.
const remoteTags = ref<string[]>([])
let loaded = false

function loadRemoteOnce(): void {
    if (loaded) {
        return
    }
    loaded = true
    fetchKnownTags()
        .then((tags) => {
            remoteTags.value = tags
        })
        .catch(() => {
            // Autocomplete is best-effort — a failed lookup just means no
            // suggestions, never a broken editor.
            loaded = false
        })
}

/**
 * Known tags for the set_tag autocomplete: the tenant's existing tag vocabulary
 * (from contact_tags) merged with tags authored on set_tag nodes in the current
 * flow. Template values ({{...}}) are excluded — they are not literal tags.
 */
export function useKnownTags(): ComputedRef<string[]> {
    const builderStore = useBuilderStore()
    loadRemoteOnce()

    return computed<string[]>(() => {
        const seen = new Set<string>(remoteTags.value)
        const nodes = (builderStore.definition?.nodes ?? []) as FlowNode[]

        for (const node of nodes) {
            if (node.type !== 'set_tag') {
                continue
            }
            const tags = (node.config as { tags?: unknown } | undefined)?.tags
            if (!Array.isArray(tags)) {
                continue
            }
            for (const tag of tags) {
                if (typeof tag === 'string' && tag.trim() !== '' && !tag.includes('{{')) {
                    seen.add(tag.trim())
                }
            }
        }

        return [...seen].sort()
    })
}
