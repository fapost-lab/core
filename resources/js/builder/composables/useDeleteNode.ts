import {ref} from 'vue'
import {useBuilderStore} from '@builder/store/builderStore'

/**
 * Module-level state for the global `<DeleteNodeDialog />` mounted once in
 * FlowEditor — mirrors {@link useMoveNode}.
 *
 * A node that owns branches (button, rule, success/error slots) cannot just be
 * dropped: its branch chains would be stranded. Instead of silently refusing
 * the click, cards route through here — plain nodes are deleted straight away,
 * branching ones open the dialog that offers to move each branch out first.
 */
const targetNodeId = ref<string | null>(null)

export function useDeleteNode() {
    const builderStore = useBuilderStore()

    function requestDelete(nodeId: string) {
        if (!nodeId) return

        // Loop constructs are deleted wholesale by the store (loop + body +
        // loop_end) and need no branch triage.
        const node = builderStore.definition.nodes.find((n) => n.id === nodeId)
        if (node?.type === 'loop') {
            builderStore.deleteNode(nodeId)
            return
        }

        if (builderStore.collectBranchDescendantIds(nodeId).length === 0) {
            builderStore.deleteNode(nodeId)
            return
        }

        targetNodeId.value = nodeId
    }

    function close() {
        targetNodeId.value = null
    }

    return {targetNodeId, requestDelete, close}
}
