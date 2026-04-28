import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useSelectionStore = defineStore('selection', () => {
    const selectedNodeId = ref<string | null>(null)
    const activeBranch = ref<string[]>([])

    function select(nodeId: string | null) {
        selectedNodeId.value = nodeId
    }

    function clear() {
        selectedNodeId.value = null
    }

    function selectTrigger() {
        selectedNodeId.value = '__trigger__'
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
        selectTrigger,
        clear,
        setActiveBranch,
        clearBranch,
    }
})
