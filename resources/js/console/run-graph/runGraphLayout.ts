import type { ELK as ElkInstance, ElkExtendedEdge, ElkNode } from 'elkjs/lib/elk.bundled.js'
import type { DrawnFrame, DrawnGraph, DrawnNode } from './runGraph'

/** A card's size on the canvas; RunNodeCard.vue draws to it. */
export const CARD_WIDTH = 240
export const CARD_HEIGHT = 76

/** The room a frame keeps above its content for its title. */
const FRAME_TITLE = 28

export interface PlacedBox<T extends DrawnNode | DrawnFrame = DrawnNode | DrawnFrame> {
  box: T
  /** Relative to the parent frame, or to the canvas for a top-level box (what Vue Flow expects of a child node). */
  position: { x: number; y: number }
  width: number
  height: number
}

const ROOT_OPTIONS: Record<string, string> = {
  'elk.algorithm': 'layered',
  'elk.direction': 'DOWN',
  'elk.hierarchyHandling': 'INCLUDE_CHILDREN',
  'elk.layered.spacing.nodeNodeBetweenLayers': '48',
  'elk.spacing.nodeNode': '32',
  'elk.edgeRouting': 'ORTHOGONAL',
}

const FRAME_OPTIONS: Record<string, string> = {
  'elk.padding': `[top=${FRAME_TITLE + 12},left=16,bottom=16,right=16]`,
}

/** The ELK graph of a drawing: frames as compound nodes holding their boxes, every edge at the root. */
export function toElkGraph(graph: DrawnGraph): ElkNode {
  const root: ElkNode = { id: '__root', layoutOptions: ROOT_OPTIONS, children: [], edges: [] }
  const elkNodes = new Map<string, ElkNode>()

  for (const box of graph.boxes) {
    const elkNode: ElkNode =
      box.kind === 'frame'
        ? { id: box.id, layoutOptions: FRAME_OPTIONS, children: [] }
        : { id: box.id, width: CARD_WIDTH, height: CARD_HEIGHT }

    elkNodes.set(box.id, elkNode)

    const parent = box.parent === null ? root : elkNodes.get(box.parent)
    ;(parent ?? root).children!.push(elkNode)
  }

  root.edges = graph.edges
    .filter((edge) => elkNodes.has(edge.source) && elkNodes.has(edge.target))
    .map((edge): ElkExtendedEdge => ({ id: edge.id, sources: [edge.source], targets: [edge.target] }))

  return root
}

/** Places a laid-out ELK graph's boxes back on the drawing's boxes, parents first. */
export function placeBoxes(graph: DrawnGraph, laidOut: ElkNode): PlacedBox[] {
  const placed = new Map<string, { x: number; y: number; width: number; height: number }>()

  const walk = (node: ElkNode): void => {
    for (const child of node.children ?? []) {
      placed.set(child.id, { x: child.x ?? 0, y: child.y ?? 0, width: child.width ?? CARD_WIDTH, height: child.height ?? CARD_HEIGHT })
      walk(child)
    }
  }

  walk(laidOut)

  return graph.boxes.map((box) => {
    const at = placed.get(box.id) ?? { x: 0, y: 0, width: CARD_WIDTH, height: CARD_HEIGHT }

    return { box, position: { x: at.x, y: at.y }, width: at.width, height: at.height }
  })
}

let elk: ElkInstance | null = null

/** Lays a drawing out with ELK's layered algorithm, nested frames included. ELK is large: it loads on first use. */
export async function layoutRunGraph(graph: DrawnGraph): Promise<PlacedBox[]> {
  if (elk === null) {
    const { default: ELK } = await import('elkjs/lib/elk.bundled.js')
    elk = new ELK()
  }

  return placeBoxes(graph, await elk.layout(toElkGraph(graph)))
}
