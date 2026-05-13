/**
 * Enumerate the outgoing slots a node exposes so callers (Move-to dialog,
 * orphan rescue, future DnD) can present them as drop targets. Stays
 * shape-driven — no runtime introspection — because the config object is
 * what the renderer and validator both consume.
 */

export interface NodeHandle {
  handle: string
  label: string
}

export interface FlowNodeLike {
  id: string
  type: string
  version?: number
  config?: Record<string, unknown> | null
}

export function nodeHandles(node: FlowNodeLike): NodeHandle[] {
  if (node.type === 'send_message') {
    return sendMessageHandles(node)
  }

  if (node.type === 'condition' || node.type === 'branch') {
    return conditionHandles(node)
  }

  if (node.type === 'end') {
    return []
  }

  return [{handle: 'default', label: 'Next'}]
}

function sendMessageHandles(node: FlowNodeLike): NodeHandle[] {
  const config = (node.config ?? {}) as Record<string, unknown>
  const buttons = Array.isArray(config.buttons) ? (config.buttons as Array<Record<string, unknown>>) : []
  const isReply = config.keyboard_mode === 'reply'
  const hasKeyboard = config.content_type === 'text_with_keyboard'

  if (hasKeyboard && isReply) {
    // Reply keyboard is terminal — no outgoing slots.
    return []
  }

  if (hasKeyboard && buttons.length > 0) {
    return buttons.map((btn, idx) => ({
      handle: String(btn.id ?? ''),
      label: buttonLabel(btn) || `Button ${idx + 1}`,
    })).filter((h) => h.handle !== '')
  }

  return [{handle: 'default', label: 'Next'}]
}

function conditionHandles(node: FlowNodeLike): NodeHandle[] {
  const config = (node.config ?? {}) as Record<string, unknown>
  const rules = Array.isArray(config.rules) ? (config.rules as Array<Record<string, unknown>>) : []
  const handles: NodeHandle[] = rules.map((rule, idx) => ({
    handle: String(rule.handle ?? `rule_${idx}`),
    label: typeof rule.label === 'string' && rule.label !== '' ? rule.label : `Rule ${idx + 1}`,
  }))
  handles.push({handle: 'default', label: 'Otherwise'})
  return handles
}

function buttonLabel(button: Record<string, unknown>): string {
  const raw = button.label
  if (!raw) return ''
  if (typeof raw === 'string') return raw
  if (typeof raw === 'object') {
    const values = Object.values(raw as Record<string, unknown>)
    return values.length > 0 ? String(values[0] ?? '') : ''
  }
  return String(raw)
}

/**
 * Collect node IDs reachable from `rootId`. The {@link withDefault}
 * flag mirrors the two move-modes:
 *
 *   - `false` (default, single-node move): only follow non-default
 *     outgoing handles. The default tail stays put when the node
 *     moves, so its nodes remain valid destinations.
 *   - `true` (move-with-descendants): follow every outgoing edge,
 *     since the whole subtree travels along.
 *
 * Used to exclude self + subtree from move-target lists so the author
 * can't re-parent a node under itself and create a cycle.
 */
export function descendantIds(
  rootId: string,
  edges: Array<{ from: string; to: string; handle?: string | null }>,
  withDefault = true,
): Set<string> {
  const result = new Set<string>([rootId])
  const stack = [rootId]
  while (stack.length > 0) {
    const current = stack.pop()!
    for (const edge of edges) {
      if (edge.from !== current) continue
      const handle = edge.handle ?? 'default'
      if (!withDefault && handle === 'default') continue
      if (result.has(edge.to)) continue
      result.add(edge.to)
      stack.push(edge.to)
    }
  }
  return result
}
