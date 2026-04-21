import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useSelectionStore = defineStore('selection', () => {
    const selectedNodeId = ref(null);

    /**
     * @param {string|null} nodeId
     */
    function select(nodeId) {
        selectedNodeId.value = nodeId;
    }

    function clear() {
        selectedNodeId.value = null;
    }

    return { selectedNodeId, select, clear };
});
