import { defineStore } from 'pinia';
import { computed, ref, watch } from 'vue';
import { useBuilderStore } from '@builder/store/builderStore';

export const useNavigationStore = defineStore('navigation', () => {
    const builderStore = useBuilderStore();

    /**
     * Breadcrumb path: each entry is a branch drill-down.
     * @type {import('vue').Ref<Array<{ nodeId: string, branchKey: string }>>}
     */
    const path = ref([])

    /**
     * Human-readable breadcrumb segments.
     * @type {import('vue').ComputedRef<Array<{ label: string, index: number }>>}
     */
    const breadcrumbs = computed(() => {
        const segments = [{ label: 'Main flow', index: -1 }]

        for (let i = 0; i < path.value.length; i++) {
            const { nodeId, branchKey } = path.value[i]
            const node = builderStore.definition.nodes.find((n) => n.id === nodeId)
            const nodeLabel = node?.label ?? nodeId
            segments.push({ label: nodeLabel, index: i })
            segments.push({ label: branchKey.toUpperCase(), index: i + 0.5 })
        }

        return segments
    })

    /**
     * @param {string} nodeId
     * @param {string} branchKey
     */
    function navigateToBranch(nodeId, branchKey) {
        path.value = [...path.value, { nodeId, branchKey }]
    }

    /**
     * @param {number} index  Truncate path to this position (exclusive).
     */
    function navigateUp(index) {
        path.value = path.value.slice(0, index)
    }

    function navigateToRoot() {
        path.value = []
    }

    // Prune stale path entries when nodes are deleted.
    watch(
        () => builderStore.definition.nodes,
        (nodes) => {
            const ids = new Set(nodes.map((n) => n.id))
            const firstStale = path.value.findIndex((entry) => !ids.has(entry.nodeId))
            if (firstStale !== -1) {
                path.value = path.value.slice(0, firstStale)
            }
        },
        { deep: true },
    )

    return { path, breadcrumbs, navigateToBranch, navigateUp, navigateToRoot }
})
