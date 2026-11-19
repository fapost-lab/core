import {defineStore} from 'pinia'
import {computed, ref} from 'vue'

const TRIGGER_PREFIX = '__trigger__:'

export const useSelectionStore = defineStore('selection', () => {
    const selectedNodeId = ref<string | null>(null)
    const activeBranch = ref<string[]>([])

    function select(nodeId: string | null) {
        selectedNodeId.value = nodeId
    }

    /** Select the node, or deselect it when it is already the active one. */
    function toggle(nodeId: string) {
        selectedNodeId.value = selectedNodeId.value === nodeId ? null : nodeId
    }

    function clear() {
        selectedNodeId.value = null
    }

    function selectTrigger(id: string) {
        const key = `${TRIGGER_PREFIX}${id}`
        selectedNodeId.value = selectedNodeId.value === key ? null : key
    }

    /** The id of the selected trigger, or null when a node (or nothing) is selected. */
    const selectedTriggerId = computed<string | null>(() =>
        selectedNodeId.value?.startsWith(TRIGGER_PREFIX) ? selectedNodeId.value.slice(TRIGGER_PREFIX.length) : null,
    )

    function setActiveBranch(path: string[]) {
        activeBranch.value = path
    }

    function clearBranch() {
        activeBranch.value = []
    }

    return {
        selectedNodeId,
        activeBranch,
        select,
        toggle,
        selectTrigger,
        selectedTriggerId,
        clear,
        setActiveBranch,
        clearBranch,
    }
})
