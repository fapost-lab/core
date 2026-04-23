import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useSelectionStore = defineStore('selection', () => {
    const selectedNodeId = ref(null);
    const activeBranch = ref([]);

    /**
     * @param {string|null} nodeId
     */
    function select(nodeId) {
        selectedNodeId.value = nodeId;
    }

    function clear() {
        selectedNodeId.value = null;
    }

    function selectTrigger() {
        selectedNodeId.value = '__trigger__';
    }

    /**
     * @param {string[]} path
     */
    function setActiveBranch(path) {
        activeBranch.value = path;
    }

    function clearBranch() {
        activeBranch.value = [];
    }

    return {
        selectedNodeId,
        activeBranch,
        select,
        selectTrigger,
        clear,
        setActiveBranch,
        clearBranch,
    };
});
