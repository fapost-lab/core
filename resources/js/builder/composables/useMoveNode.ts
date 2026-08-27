import {ref} from 'vue'

/**
 * Module-level state for the global `<MoveNodeDialog />` mounted once
 * in FlowEditor. Cards anywhere call `openMoveDialog(nodeId)` without
 * prop drilling.
 */
const targetNodeId = ref<string | null>(null)

export function useMoveNode() {
  function open(nodeId: string) {
    if (!nodeId) return
    targetNodeId.value = nodeId
  }

  function close() {
    targetNodeId.value = null
  }

  return {targetNodeId, open, close}
}
