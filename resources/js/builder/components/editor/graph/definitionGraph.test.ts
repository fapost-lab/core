import { describe, expect, it, vi } from 'vitest'

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { translations: { builder: {} } } }),
}))

import type { FlowDefinition, FlowNode } from '@builder/dto/types'
import { nodeHandles } from '@builder/utils/nodeHandles'
import { layoutGraph } from '@builder/utils/graphLayout'
import { drawDefinitionGraph, loopBodySize, GRAPH_CARD_HEIGHT, GRAPH_CARD_WIDTH, GRAPH_HANDLE_ROW, layoutKey, type GraphFrameBox, type GraphNodeBox } from './definitionGraph'

function node(id: string, type: string, config: Record<string, unknown> = {}): FlowNode {
  return { id, type, version: 1, config }
}

function card(graph: ReturnType<typeof drawDefinitionGraph>, id: string): GraphNodeBox {
  return graph.boxes.find((box): box is GraphNodeBox => box.kind === 'node' && box.id === id)!
}

function frames(graph: ReturnType<typeof drawDefinitionGraph>): GraphFrameBox[] {
  return graph.boxes.filter((box): box is GraphFrameBox => box.kind === 'frame')
}

const buttons = [
  { id: 'btn-a', label: 'A' },
  { id: 'btn-b', label: 'B' },
]

const withButtons: FlowDefinition = {
  nodes: [
    node('ask', 'send_message', { content_type: 'text_with_keyboard', buttons }),
    node('a1', 'delay'),
    node('a2', 'end'),
  ],
  edges: [
    { id: 'e1', from: 'ask', to: 'a1', handle: 'btn-a' },
    { id: 'e2', from: 'a1', to: 'a2', handle: 'default' },
  ],
}

describe('drawDefinitionGraph', () => {
  it('puts what a button leads to in a frame of its own, nested under nothing at the root', () => {
    const graph = drawDefinitionGraph(withButtons, nodeHandles)

    expect(frames(graph).map((frame) => [frame.id, frame.nodeId, frame.handle, frame.parent])).toEqual([['branch:ask|btn-a', 'ask', 'btn-a', null]])
    expect(card(graph, 'ask').parent).toBeNull()
    expect(card(graph, 'a1').parent).toBe('branch:ask|btn-a')
    expect(card(graph, 'a2').parent).toBe('branch:ask|btn-a')
  })

  it('lists the handles that lead nowhere yet, and sizes the card to them', () => {
    const graph = drawDefinitionGraph(withButtons, nodeHandles)

    expect(card(graph, 'ask').freeHandles.map((handle) => handle.handle)).toEqual(['btn-b'])
    expect(card(graph, 'ask').height).toBe(GRAPH_CARD_HEIGHT + GRAPH_HANDLE_ROW)
    expect(card(graph, 'a1').freeHandles).toEqual([])
    expect(card(graph, 'a2').freeHandles).toEqual([])
  })

  it('nests a rule of a condition inside the button branch it sits in', () => {
    const graph = drawDefinitionGraph(
      {
        nodes: [...withButtons.nodes, node('check', 'condition', { rules: [{ handle: 'r1' }] }), node('r1-end', 'end')],
        edges: [
          { id: 'e1', from: 'ask', to: 'check', handle: 'btn-a' },
          { id: 'e3', from: 'check', to: 'r1-end', handle: 'r1' },
          { id: 'e4', from: 'check', to: 'a1', handle: 'default' },
          { id: 'e2', from: 'a1', to: 'a2', handle: 'default' },
        ],
      },
      nodeHandles,
    )

    expect(frames(graph).map((frame) => [frame.id, frame.parent])).toEqual([
      ['branch:ask|btn-a', null],
      ['branch:check|r1', 'branch:ask|btn-a'],
    ])
    expect(card(graph, 'r1-end').parent).toBe('branch:check|r1')
    // The default handle of a condition ("Otherwise") stays in the condition's own frame, not a new one.
    expect(card(graph, 'a1').parent).toBe('branch:ask|btn-a')
  })

  it('draws a loop body in the main flow and offers no free handle after its loop_end', () => {
    const graph = drawDefinitionGraph(
      {
        nodes: [node('loop', 'loop'), node('body', 'delay'), node('loop-end', 'loop_end', { loop_node_id: 'loop' }), node('after', 'end')],
        edges: [
          { id: 'e1', from: 'loop', to: 'body', handle: 'loop' },
          { id: 'e2', from: 'body', to: 'loop-end', handle: 'default' },
          { id: 'e3', from: 'loop', to: 'after', handle: 'default' },
        ],
      },
      nodeHandles,
    )

    expect(frames(graph)).toEqual([])
    expect(graph.boxes.every((box) => box.parent === null)).toBe(true)
    expect(card(graph, 'loop-end').freeHandles).toEqual([])
    expect(graph.edges.map((edge) => [edge.source, edge.target, edge.handle])).toEqual([
      ['loop', 'body', 'loop'],
      ['body', 'loop-end', 'default'],
      ['loop', 'after', 'default'],
    ])
  })

  it('reads an edge without a handle as the default one', () => {
    const graph = drawDefinitionGraph({ nodes: [node('m', 'send_message'), node('n', 'end')], edges: [{ id: 'e1', from: 'm', to: 'n' }] }, nodeHandles)

    expect(frames(graph)).toEqual([])
    expect(card(graph, 'm').freeHandles).toEqual([])
    expect(graph.edges[0]!.handle).toBe('default')
  })

  it('drops an edge to a node that is not in the definition', () => {
    const graph = drawDefinitionGraph({ nodes: [node('m', 'send_message')], edges: [{ id: 'e1', from: 'm', to: 'gone', handle: 'default' }] }, nodeHandles)

    expect(graph.edges).toEqual([])
    expect(card(graph, 'm').freeHandles.map((handle) => handle.handle)).toEqual(['default'])
  })

  it('never changes the definition it draws', () => {
    const definition = structuredClone(withButtons)

    drawDefinitionGraph(definition, nodeHandles)

    expect(definition).toEqual(withButtons)
  })
})

describe('layoutKey', () => {
  it('changes with the structure, not with a node config that leaves it alone', () => {
    const before = layoutKey(drawDefinitionGraph(withButtons, nodeHandles))
    const relabelled = structuredClone(withButtons)
    relabelled.nodes[1]!.config = { seconds: 30 }
    const rewired = structuredClone(withButtons)
    rewired.edges.push({ id: 'e9', from: 'ask', to: 'a2', handle: 'btn-b' })

    expect(layoutKey(drawDefinitionGraph(relabelled, nodeHandles))).toBe(before)
    expect(layoutKey(drawDefinitionGraph(rewired, nodeHandles))).not.toBe(before)
  })
})

describe('layout of the drawing', () => {
  it('places every card, a taller one for its free handles, inside its frame', async () => {
    const placed = await layoutGraph(drawDefinitionGraph(withButtons, nodeHandles), { width: GRAPH_CARD_WIDTH, height: GRAPH_CARD_HEIGHT })
    const at = (id: string) => placed.find((box) => box.box.id === id)!

    expect(placed).toHaveLength(4)
    expect(at('ask').height).toBe(GRAPH_CARD_HEIGHT + GRAPH_HANDLE_ROW)
    expect(at('branch:ask|btn-a').width).toBeGreaterThan(GRAPH_CARD_WIDTH)
    expect(at('a2').position.y).toBeGreaterThan(at('a1').position.y)
  })
})

describe('loopBodySize', () => {
  it('counts what the loop handle leads to, up to the loop_end', () => {
    const definition: FlowDefinition = {
      nodes: [node('loop', 'loop'), node('b1', 'delay'), node('b2', 'delay'), node('loop-end', 'loop_end'), node('after', 'end')],
      edges: [
        { id: 'e1', from: 'loop', to: 'b1', handle: 'loop' },
        { id: 'e2', from: 'b1', to: 'b2', handle: 'default' },
        { id: 'e3', from: 'b2', to: 'loop-end', handle: 'default' },
        { id: 'e4', from: 'loop', to: 'after', handle: 'default' },
      ],
    }

    expect(loopBodySize(definition, 'loop')).toBe(2)
    expect(loopBodySize({ ...definition, edges: [{ id: 'e1', from: 'loop', to: 'loop-end', handle: 'loop' }] }, 'loop')).toBe(0)
  })
})
