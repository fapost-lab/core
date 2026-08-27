import {defineStore} from 'pinia'
import {ref} from 'vue'

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

    function selectTrigger() {
        selectedNodeId.value = selectedNodeId.value === '__trigger__' ? null : '__trigger__'
    }

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
        clear,
        setActiveBranch,
        clearBranch,
    }
})
