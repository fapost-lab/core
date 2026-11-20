import { describe, expect, it } from 'vitest'
import { drawRunGraph, loopsOf, statusesFor, type DrawnFrame, type DrawnNode, type RunGraph, type RunGraphFlow, type RunGraphNode } from './runGraph'
import { layoutRunGraph, toElkGraph } from './runGraphLayout'

function node(id: string, type: string, run: RunGraphNode['run'] = null): RunGraphNode {
  return { id, type, version: 1, label: null, typeLabel: null, endStatus: null, run }
}

function done(handles: string[] = ['default'], visits = 1): RunGraphNode['run'] {
  return { status: 'done', visits, handles, durationMs: 1, errorClass: null }
}

function flow(overrides: Partial<RunGraphFlow>): RunGraphFlow {
  return { sessionId: 's1', status: 'completed', flowName: 'Main', nodes: [], edges: [], loops: [], children: [], ...overrides }
}

const branching: RunGraph = {
  truncated: false, limit: 20_000,
  flow: flow({
    nodes: [node('start', 'send_message', done()), node('check', 'condition', done(['rule-1'])), node('yes', 'end', done([])), node('no', 'end')],
    edges: [
      { id: 'e1', from: 'start', to: 'check', handle: 'default' },
      { id: 'e2', from: 'check', to: 'yes', handle: 'rule-1' },
      { id: 'e3', from: 'check', to: 'no', handle: 'else' },
    ],
  }),
}

function boxNode(graph: ReturnType<typeof drawRunGraph>, id: string): DrawnNode {
  return graph.boxes.find((box): box is DrawnNode => box.kind === 'node' && box.id === id)!
}

describe('drawRunGraph', () => {
  it('lights the path the run took and dims what it never reached', () => {
    const drawn = drawRunGraph(branching)

    expect(boxNode(drawn, 's1:yes').status).toBe('done')
    expect(boxNode(drawn, 's1:no').status).toBe('untaken')
    expect(drawn.edges.map((edge) => [edge.id, edge.taken])).toEqual([
      ['s1:e1', true],
      ['s1:e2', true],
      ['s1:e3', false],
    ])
  })

  it('puts what a branching node leads to through a branch handle into a frame of that branch', () => {
    const drawn = drawRunGraph(branching)
    const frames = drawn.boxes.filter((box): box is DrawnFrame => box.kind === 'frame')

    expect(frames.map((frame) => [frame.id, frame.title, frame.status, frame.parent])).toEqual([
      ['branch:s1:check|rule-1', 'rule-1', 'done', null],
      ['branch:s1:check|else', 'else', 'untaken', null],
    ])
    expect(boxNode(drawn, 's1:yes').parent).toBe('branch:s1:check|rule-1')
    expect(boxNode(drawn, 's1:start').parent).toBeNull()
    // Parents come before their children.
    const order = drawn.boxes.map((box) => box.id)
    expect(order.indexOf('branch:s1:check|rule-1')).toBeLessThan(order.indexOf('s1:yes'))
  })

  it('nests a branch inside the branch its node sits in', () => {
    const drawn = drawRunGraph({
      truncated: false, limit: 20_000,
      flow: flow({
        nodes: [node('a', 'condition'), node('b', 'condition'), node('c', 'end')],
        edges: [
          { id: 'e1', from: 'a', to: 'b', handle: 'r1' },
          { id: 'e2', from: 'b', to: 'c', handle: 'r2' },
        ],
      }),
    })

    const inner = drawn.boxes.find((box) => box.id === 'branch:s1:b|r2')!
    expect(inner.parent).toBe('branch:s1:a|r1')
    expect(boxNode(drawn, 's1:c').parent).toBe('branch:s1:b|r2')
  })

  it('draws a subflow child in a frame of its own, linked from the node that started it', () => {
    const drawn = drawRunGraph({
      truncated: false, limit: 20_000,
      flow: flow({
        nodes: [node('call', 'subflow', done(['success']))],
        children: [{ nodeId: 'call', count: 3, flow: flow({ sessionId: 's2', flowName: 'Child', nodes: [node('inner', 'end', done([]))] }) }],
      }),
    })

    const frame = drawn.boxes.find((box): box is DrawnFrame => box.id === 'subflow:s2')!
    expect([frame.frame, frame.title, frame.count, frame.status]).toEqual(['subflow', 'Child', 3, 'done'])
    expect(boxNode(drawn, 's2:inner').parent).toBe('subflow:s2')
    expect(boxNode(drawn, 's2:inner').sessionId).toBe('s2')
    expect(drawn.edges).toContainEqual({ id: 's1:call->subflow:s2', source: 's1:call', target: 'subflow:s2', handle: 'subflow', taken: true })
  })
})

describe('loops', () => {
  const looped: RunGraph = {
    truncated: false, limit: 20_000,
    flow: flow({
      nodes: [node('loop', 'loop', done(['loop', 'default'], 4)), node('body', 'assign', done(['default'], 3)), node('skip', 'assign', done(['default'], 1))],
      edges: [
        { id: 'e1', from: 'loop', to: 'body', handle: 'loop' },
        { id: 'e2', from: 'body', to: 'skip', handle: 'default' },
      ],
      loops: [
        {
          nodeId: 'loop',
          passes: [
            { pass: 1, nodes: [{ id: 'body', status: 'done', handle: 'default' }, { id: 'skip', status: 'done', handle: 'default' }] },
            { pass: 2, nodes: [{ id: 'body', status: 'failed', handle: null }] },
          ],
        },
      ],
    }),
  }

  it('draws a loop once with how many passes it ran, showing the last pass by default', () => {
    const drawn = drawRunGraph(looped)

    expect(boxNode(drawn, 's1:loop').loop).toEqual({ key: 's1:loop', count: 2, index: 1, pass: 2 })
    expect(boxNode(drawn, 's1:body').status).toBe('failed')
    expect(boxNode(drawn, 's1:skip').status).toBe('untaken')
    expect(drawn.edges.find((edge) => edge.id === 's1:e2')!.taken).toBe(false)
  })

  it('recolours the path by the chosen pass', () => {
    const drawn = drawRunGraph(looped, { 's1:loop': 0 })

    expect(boxNode(drawn, 's1:loop').loop?.index).toBe(0)
    expect(boxNode(drawn, 's1:body').status).toBe('done')
    expect(boxNode(drawn, 's1:skip').status).toBe('done')
    expect(drawn.edges.find((edge) => edge.id === 's1:e2')!.taken).toBe(true)
  })

  it('ignores a chosen pass the loop does not have', () => {
    expect(statusesFor(looped.flow, { 's1:loop': 7 }).get('body')).toBe('failed')
  })

  it('lists the loops of the run, its subflow children included', () => {
    const withChild = flow({ ...looped.flow, children: [{ nodeId: 'loop', count: 1, flow: flow({ ...looped.flow, sessionId: 's2' }) }] })

    expect(loopsOf(withChild).map((loop) => [loop.key, loop.count])).toEqual([
      ['s1:loop', 2],
      ['s2:loop', 2],
    ])
  })
})

describe('layout', () => {
  it('hands ELK frames as compound nodes and every edge at the root', () => {
    const elk = toElkGraph(drawRunGraph(branching))

    expect(elk.children!.map((child) => child.id)).toEqual(['branch:s1:check|rule-1', 'branch:s1:check|else', 's1:start', 's1:check'])
    expect(elk.children![0]!.children!.map((child) => child.id)).toEqual(['s1:yes'])
    expect(elk.edges).toHaveLength(3)
  })

  it('places every box, children relative to their frame', async () => {
    const placed = await layoutRunGraph(drawRunGraph(branching))
    const frame = placed.find((box) => box.box.id === 'branch:s1:check|rule-1')!
    const inside = placed.find((box) => box.box.id === 's1:yes')!

    expect(placed).toHaveLength(6)
    expect(frame.width).toBeGreaterThan(inside.width)
    expect(inside.position.y).toBeGreaterThan(0)
  })
})
