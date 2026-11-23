/**
 * How a flow's graph is cut into branch frames, shared by the builder's graph mode and the console's run graph: the
 * nodes a branching node's non-default handle leads to sit in a frame `<branching node id>|<handle>`, nested where the
 * branching node itself sits. Pure: no drawing, no layout.
 */

/** The least of a flow both drawings have: its nodes' ids and types, its edges. A missing handle is `default`. */
export interface FramedGraph {
  nodes: { id: string; type: string }[]
  edges: { from: string; to: string; handle?: string | null }[]
}

/** Node types whose non-default handles open a branch frame (rules of a condition, buttons of a message). */
export const BRANCHING_TYPES: ReadonlySet<string> = new Set(['condition', 'branch', 'send_message'])

/** A branch frame's id is `<branching node id>|<handle>`; node ids never hold a `|`. */
export function branchingNodeOf(frameId: string): string {
  return frameId.slice(0, frameId.indexOf('|'))
}

/** The handle of a branch frame: what follows the first `|` of its id. */
export function frameHandleOf(frameId: string): string {
  return frameId.slice(frameId.indexOf('|') + 1)
}

/**
 * The branch frame each node sits in, as `<branching node id>|<handle>`; a node outside any branch has none. A node
 * reached twice (a merge) stays where it was reached first, from the roots down.
 */
export function branchOwners(flow: FramedGraph): Map<string, string> {
  const incoming = new Set(flow.edges.map((edge) => edge.to))
  const roots = flow.nodes.filter((node) => !incoming.has(node.id)).map((node) => node.id)
  const types = new Map(flow.nodes.map((node) => [node.id, node.type]))
  const owners = new Map<string, string>()
  const visited = new Set<string>()
  const stack: { id: string; owner: string | undefined }[] = roots.reverse().map((id) => ({ id, owner: undefined }))

  while (stack.length > 0) {
    const { id, owner } = stack.pop()!

    if (visited.has(id)) {
      continue
    }

    visited.add(id)

    if (owner !== undefined) {
      owners.set(id, owner)
    }

    const outgoing = flow.edges.filter((edge) => edge.from === id)

    for (const edge of [...outgoing].reverse()) {
      const handle = edge.handle ?? 'default'
      const opensBranch = BRANCHING_TYPES.has(types.get(id) ?? '') && handle !== 'default'

      stack.push({ id: edge.to, owner: opensBranch ? `${id}|${handle}` : owner })
    }
  }

  return owners
}

/** The frame a frame sits in: the one its branching node sits in, if any. */
export function parentFrameOf(frameId: string, owners: Map<string, string>): string | undefined {
  return owners.get(branchingNodeOf(frameId))
}

/** The given frames, outermost first, so a frame always comes after the frame it sits in. */
export function framesOutermostFirst(frameIds: Iterable<string>, owners: Map<string, string>): string[] {
  const depth = (frameId: string): number => {
    let level = 0

    for (let current = parentFrameOf(frameId, owners); current !== undefined && level < 100; current = parentFrameOf(current, owners)) {
      level++
    }

    return level
  }

  return [...new Set(frameIds)].sort((a, b) => depth(a) - depth(b))
}
