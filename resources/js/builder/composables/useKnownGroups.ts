import {computed, type ComputedRef} from 'vue'
import {useBuilderStore} from '@builder/store/builderStore'
import type {FlowNode} from '@builder/dto/types'

/**
 * Collects unique group names declared on Input / Assign nodes inside the
 * current flow. Used by VariableStorageEditor's GroupSelect to provide
 * autocomplete entries — keeps groups consistent across nodes without a
 * separate registry.
 */
export function useKnownGroups(): ComputedRef<string[]> {
    const builderStore = useBuilderStore()

    return computed<string[]>(() => {
        const seen = new Set<string>()
        const nodes = (builderStore.definition?.nodes ?? []) as FlowNode[]

        for (const node of nodes) {
            if (node.type !== 'input' && node.type !== 'assign') {
                continue
            }
            const config = (node.config ?? {}) as Record<string, unknown>
            const variable = config.variable as { group?: unknown } | undefined
            const flatGroup = config.group

            const candidate = (variable && typeof variable.group === 'string')
                ? variable.group
                : (typeof flatGroup === 'string' ? flatGroup : null)

            if (candidate && candidate.trim() !== '') {
                seen.add(candidate)
            }
        }

        return [...seen].sort()
    })
}
