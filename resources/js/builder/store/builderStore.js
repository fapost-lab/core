import { defineStore } from 'pinia';
import { ref } from 'vue';

/**
 * @param {unknown} raw
 * @returns {{ nodes: unknown[], edges: unknown[] }}
 */
function normalizeDefinition(raw) {
    if (raw == null) {
        return { nodes: [], edges: [] };
    }

    if (Array.isArray(raw)) {
        return { nodes: raw, edges: [] };
    }

    if (typeof raw === 'object') {
        const obj = /** @type {{ nodes?: unknown, edges?: unknown }} */ (raw);

        return {
            nodes: Array.isArray(obj.nodes) ? obj.nodes : [],
            edges: Array.isArray(obj.edges) ? obj.edges : [],
        };
    }

    return { nodes: [], edges: [] };
}

export const useBuilderStore = defineStore('builder', () => {
    const flowId = ref(null);
    const flowName = ref('');
    const draftVersion = ref(null);
    const definition = ref({ nodes: [], edges: [] });
    const saveStatus = ref('idle');

    /**
     * @param {{
     *   flowId: string,
     *   name: string,
     *   draftVersion: number,
     *   definition?: unknown,
     * }} flow
     */
    function init(flow) {
        flowId.value = flow.flowId;
        flowName.value = flow.name;
        draftVersion.value = flow.draftVersion;
        definition.value = normalizeDefinition(flow.definition);
    }

    /**
     * @param {number|null} v
     */
    function setDraftVersion(v) {
        draftVersion.value = v;
    }

    /**
     * @param {'idle'|'saving'|'saved'|'conflict'|'error'} status
     */
    function setSaveStatus(status) {
        saveStatus.value = status;
    }

    return {
        flowId,
        flowName,
        draftVersion,
        definition,
        saveStatus,
        init,
        setDraftVersion,
        setSaveStatus,
    };
});
