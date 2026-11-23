import type { FlowDefinition, FlowNode } from '@builder/dto/types'
import { branchOwners, framesOutermostFirst, frameHandleOf, branchingNodeOf, parentFrameOf } from '@builder/utils/graphFrames'
import type { NodeHandle } from '@builder/utils/nodeHandles'

/**
 * The draft's definition as the graph mode draws it: every node once as a card, the nodes a branching node's rule or
 * button leads to in a nested frame, every edge with its handle, and the handles of each node that lead nowhere yet
 * (where a new node can be added). Pure: it reads the definition and never changes it — the graph edits only through
 * the builder store's own operations. No layout here (`@builder/utils/graphLayout`).
 */

/** A card's width on the canvas, and its height with no free handles listed; GraphNodeCard.vue draws to them. */
export const GRAPH_CARD_WIDTH = 248
export const GRAPH_CARD_HEIGHT = 72
/** The coloured head of a card; the rest of GRAPH_CARD_HEIGHT is its caption row. */
export const GRAPH_CARD_HEAD = 38
/** The room one free handle takes under a card. */
export const GRAPH_HANDLE_ROW = 26

export interface GraphNodeBox {
  kind: 'node'
  /** The node's id: a card's box id is its node's. */
  id: string
  parent: string | null
  node: FlowNode
  /** The node's handles without an outgoing edge, in the order the node declares them. */
  freeHandles: NodeHandle[]
  height: number
}

export interface GraphFrameBox {
  kind: 'frame'
  id: string
  parent: string | null
  /** The branching node and the handle whose branch the frame holds. */
  nodeId: string
  handle: string
}

export interface GraphEdge {
  /** The definition edge's id. */
  id: string
  source: string
  target: string
  handle: string
}

export interface DefinitionGraph {
  /** Parents come before their children. */
  boxes: (GraphNodeBox | GraphFrameBox)[]
  edges: GraphEdge[]
}

/** The handles a node exposes (`nodeHandles` in the builder; a parameter so the drawing stays a pure function). */
export type HandlesOf = (node: FlowNode) => NodeHandle[]

/** Node types with no free handle to offer: `loop_end` closes a loop body and hands control back to its loop. */
const NO_FREE_HANDLES = new Set(['loop_end'])

export function frameBoxId(frameId: string): string {
  return `branch:${frameId}`
}

export function drawDefinitionGraph(definition: FlowDefinition, handlesOf: HandlesOf): DefinitionGraph {
  const nodes = definition.nodes ?? []
  const nodeIds = new Set(nodes.map((node) => node.id))
  const edges = (definition.edges ?? []).filter((edge) => nodeIds.has(edge.from) && nodeIds.has(edge.to))
  const owners = branchOwners({ nodes, edges })
  const boxes: (GraphNodeBox | GraphFrameBox)[] = []

  const frameIds = nodes.map((node) => owners.get(node.id)).filter((frame): frame is string => frame !== undefined)

  for (const frameId of framesOutermostFirst(frameIds, owners)) {
    const owner = parentFrameOf(frameId, owners)

    boxes.push({
      kind: 'frame',
      id: frameBoxId(frameId),
      parent: owner === undefined ? null : frameBoxId(owner),
      nodeId: branchingNodeOf(frameId),
      handle: frameHandleOf(frameId),
    })
  }

  const usedHandles = new Map<string, Set<string>>()

  for (const edge of edges) {
    const used = usedHandles.get(edge.from) ?? new Set<string>()
    used.add(edge.handle ?? 'default')
    usedHandles.set(edge.from, used)
  }

  for (const node of nodes) {
    const owner = owners.get(node.id)
    const used = usedHandles.get(node.id) ?? new Set<string>()
    const freeHandles = NO_FREE_HANDLES.has(node.type) ? [] : handlesOf(node).filter((handle) => !used.has(handle.handle))

    boxes.push({
      kind: 'node',
      id: node.id,
      parent: owner === undefined ? null : frameBoxId(owner),
      node,
      freeHandles,
      height: GRAPH_CARD_HEIGHT + freeHandles.length * GRAPH_HANDLE_ROW,
    })
  }

  return {
    boxes,
    edges: edges.map((edge) => ({ id: edge.id, source: edge.from, target: edge.to, handle: edge.handle ?? 'default' })),
  }
}

/**
 * What the drawing's layout depends on: the boxes, their nesting and heights, and the edges. A change of a node's
 * config that leaves all of that alone (its text, its label) keeps the layout.
 */
export function layoutKey(graph: DefinitionGraph): string {
  const boxes = graph.boxes.map((box) => `${box.id}<${box.parent ?? ''}${box.kind === 'node' ? `:${box.height}` : ''}`)
  const edges = graph.edges.map((edge) => `${edge.source}>${edge.target}`)

  return `${boxes.join('|')}#${edges.join('|')}`
}

/**
 * How many nodes the author put in a loop's body: what its `loop` handle leads to, up to its `loop_end` (which is not
 * counted, since it comes and goes with the loop). Deleting a loop removes them all, so the graph asks first, as the
 * tree's loop card does.
 */
export function loopBodySize(definition: FlowDefinition, loopId: string): number {
  const edges = definition.edges ?? []
  const types = new Map((definition.nodes ?? []).map((node) => [node.id, node.type]))
  const seen = new Set<string>()
  const stack = edges.filter((edge) => edge.from === loopId && (edge.handle ?? 'default') === 'loop').map((edge) => edge.to)

  while (stack.length > 0) {
    const id = stack.pop()!

    if (seen.has(id) || id === loopId || types.get(id) === 'loop_end' || !types.has(id)) {
      continue
    }

    seen.add(id)
    stack.push(...edges.filter((edge) => edge.from === id).map((edge) => edge.to))
  }

  return seen.size
}
