import type { ElkNode } from 'elkjs/lib/elk.bundled.js'
import { layoutGraph, toElkGraph as toSharedElkGraph, type PlacedBox as SharedPlacedBox } from '@builder/utils/graphLayout'
import type { DrawnFrame, DrawnGraph, DrawnNode } from './runGraph'

/** A card's size on the canvas; RunNodeCard.vue draws to it. */
export const CARD_WIDTH = 240
export const CARD_HEIGHT = 76

const CARD = { width: CARD_WIDTH, height: CARD_HEIGHT }

export type PlacedBox<T extends DrawnNode | DrawnFrame = DrawnNode | DrawnFrame> = SharedPlacedBox<T>

/** The ELK graph of a run drawing: frames as compound nodes holding their boxes, every edge at the root. */
export function toElkGraph(graph: DrawnGraph): ElkNode {
  return toSharedElkGraph(graph, CARD)
}

/** Lays a run drawing out with ELK's layered algorithm (`@builder/utils/graphLayout`), nested frames included. */
export async function layoutRunGraph(graph: DrawnGraph): Promise<PlacedBox[]> {
  return layoutGraph(graph, CARD)
}
