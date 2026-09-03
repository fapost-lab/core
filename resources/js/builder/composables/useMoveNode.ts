import {ref} from 'vue'

/**
 * Module-level state for the global `<MoveNodeDialog />` mounted once
 * in FlowEditor. Cards anywhere call `openMoveDialog(nodeId)` without
 * prop drilling.
 */
const targetNodeId = ref<string | null>(null)

/**
 * Preset for the dialog's "move with descendants" toggle. Set when the caller
 * already knows a whole chain is travelling — e.g. rescuing a branch out of a
 * node that is about to be deleted.
 */
const targetAsChain = ref(false)

export function useMoveNode() {
  function open(nodeId: string, options: { chain?: boolean } = {}) {
    if (!nodeId) return
    targetNodeId.value = nodeId
    targetAsChain.value = options.chain ?? false
  }

  function close() {
    targetNodeId.value = null
    targetAsChain.value = false
  }

  return {targetNodeId, targetAsChain, open, close}
}
