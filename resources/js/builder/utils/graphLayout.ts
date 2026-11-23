import type { ELK as ElkInstance, ElkExtendedEdge, ElkNode } from 'elkjs/lib/elk.bundled.js'

/**
 * ELK's layered layout for a flow drawn as cards and nested frames, shared by the builder's graph mode and the
 * console's run graph. ELK is large: it loads on first use, so it lands in whichever lazy chunk calls it.
 */

/** A card or a frame to lay out. A frame's size comes from what it holds; a card's from {@link CardSize}. */
export interface LayoutBox {
  id: string
  kind: 'node' | 'frame'
  /** The frame the box sits in; parents come before their children. */
  parent: string | null
  /** A card's own height, when it differs from {@link CardSize} (a card that lists its free handles). */
  height?: number
}

export interface LayoutEdge {
  id: string
  source: string
  target: string
}

export interface LayoutGraph<T extends LayoutBox = LayoutBox> {
  boxes: T[]
  edges: LayoutEdge[]
}

export interface CardSize {
  width: number
  height: number
}

export interface PlacedBox<T extends LayoutBox = LayoutBox> {
  box: T
  /** Relative to the parent frame, or to the canvas for a top-level box (what Vue Flow expects of a child node). */
  position: { x: number; y: number }
  width: number
  height: number
}

/** The room a frame keeps above its content for its title. */
const FRAME_TITLE = 28

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
export function toElkGraph(graph: LayoutGraph, card: CardSize): ElkNode {
  const root: ElkNode = { id: '__root', layoutOptions: ROOT_OPTIONS, children: [], edges: [] }
  const elkNodes = new Map<string, ElkNode>()

  for (const box of graph.boxes) {
    const elkNode: ElkNode =
      box.kind === 'frame'
        ? { id: box.id, layoutOptions: FRAME_OPTIONS, children: [] }
        : { id: box.id, width: card.width, height: box.height ?? card.height }

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
export function placeBoxes<T extends LayoutBox>(graph: LayoutGraph<T>, laidOut: ElkNode, card: CardSize): PlacedBox<T>[] {
  const placed = new Map<string, { x: number; y: number; width: number; height: number }>()

  const walk = (node: ElkNode): void => {
    for (const child of node.children ?? []) {
      placed.set(child.id, { x: child.x ?? 0, y: child.y ?? 0, width: child.width ?? card.width, height: child.height ?? card.height })
      walk(child)
    }
  }

  walk(laidOut)

  return graph.boxes.map((box) => {
    const at = placed.get(box.id) ?? { x: 0, y: 0, width: card.width, height: card.height }

    return { box, position: { x: at.x, y: at.y }, width: at.width, height: at.height }
  })
}

let elk: ElkInstance | null = null

/** Lays a drawing out with ELK's layered algorithm, nested frames included. */
export async function layoutGraph<T extends LayoutBox>(graph: LayoutGraph<T>, card: CardSize): Promise<PlacedBox<T>[]> {
  if (elk === null) {
    const { default: ELK } = await import('elkjs/lib/elk.bundled.js')
    elk = new ELK()
  }

  return placeBoxes(graph, await elk.layout(toElkGraph(graph, card)), card)
}
