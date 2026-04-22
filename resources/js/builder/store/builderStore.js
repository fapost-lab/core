import { defineStore } from 'pinia';
import { computed, ref } from 'vue';
import { nanoid } from 'nanoid';
import { buildTree } from '@builder/utils/buildTree';
import { useSelectionStore } from '@builder/store/selectionStore';

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
    const tree = computed(() => buildTree(
        definition.value.nodes ?? [],
        definition.value.edges ?? [],
    ));
    const saveStatus = ref('idle');
    const activeTab = ref('builder');
    const undoStack = ref([]);
    const redoStack = ref([]);
    const MAX_UNDO = 50;

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

    /**
     * @param {'builder'|'content'} tab
     */
    function setActiveTab(tab) {
        activeTab.value = tab;
    }

    function snapshot() {
        undoStack.value.push(JSON.stringify(definition.value));
        if (undoStack.value.length > MAX_UNDO) {
            undoStack.value.shift();
        }
        redoStack.value = [];
    }

    function undo() {
        if (!undoStack.value.length) {
            return;
        }

        redoStack.value.push(JSON.stringify(definition.value));
        definition.value = JSON.parse(undoStack.value.pop());
    }

    function redo() {
        if (!redoStack.value.length) {
            return;
        }

        undoStack.value.push(JSON.stringify(definition.value));
        definition.value = JSON.parse(redoStack.value.pop());
    }

    /**
     * @param {string} afterNodeId
     * @param {string} handle
     * @param {string} type
     * @param {number} version
     * @returns {string|null}
     */
    function insertNode(afterNodeId, handle = 'default', type, version) {
        const newNodeId = nanoid(10);

        if (!afterNodeId) {
            const incomingNodeIds = new Set(definition.value.edges.map((edge) => edge.to));
            const roots = definition.value.nodes.filter((node) => !incomingNodeIds.has(node.id));

            if (roots.length !== 1) {
                return null;
            }
        }

        snapshot();

        const edgeIndex = definition.value.edges.findIndex(
            (edge) => edge.from === afterNodeId && (edge.handle ?? 'default') === handle,
        );

        const newNode = {
            id: newNodeId,
            type,
            version,
            config: {},
        };

        definition.value.nodes.push(newNode);

        if (!afterNodeId) {
            const incomingNodeIds = new Set(definition.value.edges.map((edge) => edge.to));
            const roots = definition.value.nodes.filter((node) => node.id !== newNodeId && !incomingNodeIds.has(node.id));

            definition.value.edges.push({
                id: nanoid(10),
                from: newNodeId,
                to: roots[0].id,
                handle: 'default',
            });
        } else if (edgeIndex !== -1) {
            const existingEdge = definition.value.edges[edgeIndex];
            const previousTargetId = existingEdge.to;

            definition.value.edges[edgeIndex] = {
                id: nanoid(10),
                from: afterNodeId,
                to: newNodeId,
                handle,
            };

            definition.value.edges.push({
                id: nanoid(10),
                from: newNodeId,
                to: previousTargetId,
                handle: 'default',
            });
        } else {
            definition.value.edges.push({
                id: nanoid(10),
                from: afterNodeId,
                to: newNodeId,
                handle,
            });
        }

        return newNodeId;
    }

    /**
     * @param {string} nodeId
     * @returns {boolean}
     */
    function deleteNode(nodeId) {
        const selectionStore = useSelectionStore();

        if (!nodeId) {
            return false;
        }

        const incomingEdges = definition.value.edges.filter((edge) => edge.to === nodeId);
        const outgoingDefaultEdges = definition.value.edges.filter(
            (edge) => edge.from === nodeId && (edge.handle ?? 'default') === 'default',
        );
        const hasNonDefaultOutgoingEdges = definition.value.edges.some(
            (edge) => edge.from === nodeId && (edge.handle ?? 'default') !== 'default',
        );

        if (hasNonDefaultOutgoingEdges || incomingEdges.length > 1 || outgoingDefaultEdges.length > 1) {
            return false;
        }

        snapshot();

        const incomingEdge = incomingEdges[0] ?? null;
        const outgoingEdge = outgoingDefaultEdges[0] ?? null;

        definition.value.nodes = definition.value.nodes.filter((node) => node.id !== nodeId);
        definition.value.edges = definition.value.edges.filter((edge) => edge.from !== nodeId && edge.to !== nodeId);

        if (incomingEdge && outgoingEdge) {
            definition.value.edges.push({
                id: nanoid(10),
                from: incomingEdge.from,
                to: outgoingEdge.to,
                handle: incomingEdge.handle ?? 'default',
            });
        }

        if (selectionStore.selectedNodeId === nodeId) {
            selectionStore.clear();
        }

        return true;
    }

    /**
     * @param {string} nodeId
     */
    function moveNodeUp(nodeId) {
        const incomingEdge = definition.value.edges.find((edge) => edge.to === nodeId);
        if (!incomingEdge) {
            return;
        }

        const predecessorId = incomingEdge.from;
        const predecessorIncoming = definition.value.edges.find((edge) => edge.to === predecessorId);
        if (!predecessorIncoming) {
            return;
        }

        snapshot();
        swapAdjacentNodes(predecessorId, nodeId);
    }

    /**
     * @param {string} nodeId
     */
    function moveNodeDown(nodeId) {
        const outgoingEdge = definition.value.edges.find(
            (edge) => edge.from === nodeId && (edge.handle ?? 'default') === 'default',
        );
        if (!outgoingEdge) {
            return;
        }

        snapshot();
        swapAdjacentNodes(nodeId, outgoingEdge.to);
    }

    /**
     * @param {string} firstId
     * @param {string} secondId
     */
    function swapAdjacentNodes(firstId, secondId) {
        const beforeFirst = definition.value.edges.find((edge) => edge.to === firstId);
        const between = definition.value.edges.find(
            (edge) => edge.from === firstId && edge.to === secondId && (edge.handle ?? 'default') === 'default',
        );
        const afterSecond = definition.value.edges.find(
            (edge) => edge.from === secondId && (edge.handle ?? 'default') === 'default',
        );

        if (!between) {
            return;
        }

        if (beforeFirst) {
            beforeFirst.to = secondId;
        }
        between.from = secondId;
        between.to = firstId;
        if (afterSecond) {
            afterSecond.from = firstId;
        }
    }

    /**
     * @param {string} nodeId
     * @param {Record<string, unknown>} patch
     * Merges a partial config patch into existing node config.
     */
    function updateNodeConfig(nodeId, patch) {
        const node = definition.value.nodes.find((n) => n.id === nodeId);
        if (node) {
            node.config = { ...(node.config ?? {}), ...patch };
        }
    }

    return {
        flowId,
        flowName,
        draftVersion,
        definition,
        tree,
        saveStatus,
        activeTab,
        undoStack,
        redoStack,
        init,
        setDraftVersion,
        setSaveStatus,
        setActiveTab,
        undo,
        redo,
        updateNodeConfig,
        insertNode,
        deleteNode,
        moveNodeUp,
        moveNodeDown,
    };
});
