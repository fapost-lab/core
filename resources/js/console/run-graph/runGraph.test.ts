import { describe, expect, it } from 'vitest'
import { drawRunGraph, isUnrolled, loopsOf, runKindsOf, statusesFor, UNROLL_MAX_PASSES, type DrawnFrame, type DrawnNode, type RunGraph, type RunGraphFlow, type RunGraphLoop, type RunGraphNode } from './runGraph'
import { layoutRunGraph, toElkGraph } from './runGraphLayout'

function node(id: string, type: string, run: RunGraphNode['run'] = null): RunGraphNode {
  return { id, type, version: 1, label: null, typeLabel: null, endStatus: null, run }
}

function done(handles: string[] = ['default'], visits = 1): RunGraphNode['run'] {
  return { status: 'done', visits, handles, durationMs: 1, errorClass: null }
}

function flow(overrides: Partial<RunGraphFlow>): RunGraphFlow {
  return { sessionId: 's1', status: 'completed', runKind: 'automation', flowName: 'Main', nodes: [], edges: [], loops: [], children: [], ...overrides }
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
  // More passes than a loop is unrolled for: the loop is drawn once, with a pass switcher.
  const filler: RunGraphLoop['passes'] = Array.from({ length: UNROLL_MAX_PASSES - 1 }, (_, index) => ({
    pass: index + 1,
    nodes: [{ id: 'body', status: 'done' as const, handle: 'default' }],
  }))
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
            ...filler,
            { pass: UNROLL_MAX_PASSES, nodes: [{ id: 'body', status: 'done', handle: 'default' }, { id: 'skip', status: 'done', handle: 'default' }] },
            { pass: UNROLL_MAX_PASSES + 1, nodes: [{ id: 'body', status: 'failed', handle: null }] },
          ],
        },
      ],
    }),
  }

  it('draws a loop once with how many passes it ran, showing the last pass by default', () => {
    const drawn = drawRunGraph(looped)

    expect(isUnrolled(looped.flow, 'loop')).toBe(false)
    expect(boxNode(drawn, 's1:loop').loop).toEqual({ key: 's1:loop', count: UNROLL_MAX_PASSES + 1, index: UNROLL_MAX_PASSES, pass: UNROLL_MAX_PASSES + 1 })
    expect(boxNode(drawn, 's1:body').status).toBe('failed')
    expect(boxNode(drawn, 's1:skip').status).toBe('untaken')
    expect(drawn.edges.find((edge) => edge.id === 's1:e2')!.taken).toBe(false)
  })

  it('recolours the path by the chosen pass', () => {
    const drawn = drawRunGraph(looped, { 's1:loop': UNROLL_MAX_PASSES - 1 })

    expect(boxNode(drawn, 's1:loop').loop?.index).toBe(UNROLL_MAX_PASSES - 1)
    expect(boxNode(drawn, 's1:body').status).toBe('done')
    expect(boxNode(drawn, 's1:skip').status).toBe('done')
    expect(drawn.edges.find((edge) => edge.id === 's1:e2')!.taken).toBe(true)
  })

  it('ignores a chosen pass the loop does not have', () => {
    expect(statusesFor(looped.flow, { 's1:loop': 70 }).get('body')).toBe('failed')
  })

  it('lists the loops of the run, its subflow children included', () => {
    const withChild = flow({ ...looped.flow, children: [{ nodeId: 'loop', count: 1, flow: flow({ ...looped.flow, sessionId: 's2' }) }] })

    expect(loopsOf(withChild).map((loop) => [loop.key, loop.count])).toEqual([
      ['s1:loop', UNROLL_MAX_PASSES + 1],
      ['s2:loop', UNROLL_MAX_PASSES + 1],
    ])
  })
})

describe('unrolled loops', () => {
  // loop -loop-> a -> b, then loop -default-> after; two passes, the second failing at a.
  function small(overrides: Partial<RunGraphFlow> = {}): RunGraphFlow {
    return flow({
      nodes: [node('loop', 'loop', done(['loop', 'default'], 3)), node('a', 'assign', done(['default'], 2)), node('b', 'assign', done(['default'], 1)), node('after', 'end', done([]))],
      edges: [
        { id: 'e1', from: 'loop', to: 'a', handle: 'loop' },
        { id: 'e2', from: 'a', to: 'b', handle: 'default' },
        { id: 'e3', from: 'loop', to: 'after', handle: 'default' },
      ],
      loops: [
        {
          nodeId: 'loop',
          passes: [
            { pass: 1, nodes: [{ id: 'a', status: 'done', handle: 'default' }, { id: 'b', status: 'done', handle: 'default' }] },
            { pass: 2, nodes: [{ id: 'a', status: 'failed', handle: null }] },
          ],
        },
      ],
      ...overrides,
    })
  }

  it('draws a small loop as a frame per pass, each holding the body with what it did in that pass', () => {
    const drawn = drawRunGraph({ truncated: false, limit: 20_000, flow: small() })
    const frames = drawn.boxes.filter((box): box is DrawnFrame => box.kind === 'frame')

    expect(frames.map((frame) => [frame.id, frame.frame, frame.title, frame.parent])).toEqual([
      ['pass:s1:loop:1', 'pass', '1', null],
      ['pass:s1:loop:2', 'pass', '2', null],
    ])
    // The body is drawn only inside the passes.
    expect(drawn.boxes.map((box) => box.id)).not.toContain('s1:a')
    expect([boxNode(drawn, 's1:a@1').status, boxNode(drawn, 's1:b@1').status]).toEqual(['done', 'done'])
    expect([boxNode(drawn, 's1:a@2').status, boxNode(drawn, 's1:b@2').status]).toEqual(['failed', 'untaken'])
    expect(boxNode(drawn, 's1:a@2')).toMatchObject({ parent: 'pass:s1:loop:2', pass: 2, sessionId: 's1' })
    expect(boxNode(drawn, 's1:a@2').node.id).toBe('a')
    expect(boxNode(drawn, 's1:loop').pass).toBeNull()

    expect(drawn.edges.map((edge) => [edge.id, edge.source, edge.target, edge.taken])).toEqual([
      ['s1:e2@1', 's1:a@1', 's1:b@1', true],
      ['s1:loop->pass:s1:loop:1', 's1:loop', 'pass:s1:loop:1', true],
      ['s1:e2@2', 's1:a@2', 's1:b@2', false],
      ['s1:loop->pass:s1:loop:2', 's1:loop', 'pass:s1:loop:2', true],
      ['s1:e3', 's1:loop', 's1:after', true],
    ])
  })

  it('needs no pass switcher for an unrolled loop', () => {
    expect(loopsOf(small()).map((loop) => [loop.key, loop.unrolled])).toEqual([['s1:loop', true]])
  })

  it('puts the passes where the loop node sits', () => {
    const drawn = drawRunGraph({
      truncated: false, limit: 20_000,
      flow: small({
        nodes: [node('check', 'condition', done(['r1'])), ...small().nodes],
        edges: [{ id: 'e0', from: 'check', to: 'loop', handle: 'r1' }, ...small().edges],
      }),
    })

    expect(drawn.boxes.find((box) => box.id === 'pass:s1:loop:1')!.parent).toBe('branch:s1:check|r1')
    expect(drawn.boxes.map((box) => box.id)).not.toContain('branch:s1:a|default')
  })

  it.each([
    ['too many passes', (base: RunGraphFlow) => ({ loops: [{ nodeId: 'loop', passes: Array.from({ length: UNROLL_MAX_PASSES + 1 }, (_, index) => ({ pass: index + 1, nodes: [] })) }] })],
    ['too many cards', (base: RunGraphFlow) => ({
      nodes: [...base.nodes, ...Array.from({ length: 9 }, (_, index) => node(`x${index}`, 'assign'))],
      edges: [...base.edges, ...Array.from({ length: 9 }, (_, index) => ({ id: `x${index}`, from: index === 0 ? 'b' : `x${index - 1}`, to: `x${index}`, handle: 'default' }))],
    })],
    ['a branching node in the body', (base: RunGraphFlow) => ({ nodes: base.nodes.map((item) => (item.id === 'b' ? node('b', 'condition') : item)) })],
    ['a subflow in the body', (base: RunGraphFlow) => ({ nodes: base.nodes.map((item) => (item.id === 'b' ? node('b', 'subflow') : item)) })],
    ['a loop in the body', (base: RunGraphFlow) => ({ nodes: base.nodes.map((item) => (item.id === 'b' ? node('b', 'loop') : item)) })],
    ['an edge into the body from outside', (base: RunGraphFlow) => ({ edges: [...base.edges, { id: 'in', from: 'after', to: 'b', handle: 'default' }] })],
    ['no passes', () => ({ loops: [] })],
  ])('keeps a loop with %s drawn once', (_, change) => {
    const base = small()
    const changed = { ...base, ...change(base) }

    expect(isUnrolled(changed, 'loop')).toBe(false)
    expect(drawRunGraph({ truncated: false, limit: 20_000, flow: changed }).boxes.some((box) => box.kind === 'frame' && box.frame === 'pass')).toBe(false)
  })

  it('lays the passes out with their copies inside them', async () => {
    const placed = await layoutRunGraph(drawRunGraph({ truncated: false, limit: 20_000, flow: small() }))
    const pass = placed.find((box) => box.box.id === 'pass:s1:loop:1')!
    const copy = placed.find((box) => box.box.id === 's1:a@1')!

    expect(placed.map((box) => box.box.id).sort()).toEqual(['pass:s1:loop:1', 'pass:s1:loop:2', 's1:a@1', 's1:a@2', 's1:after', 's1:b@1', 's1:b@2', 's1:loop'])
    expect(copy.box.parent).toBe('pass:s1:loop:1')
    expect(pass.width).toBeGreaterThan(copy.width)
    expect(copy.position.y).toBeGreaterThan(0)
  })

  it('keeps a loop inside another loop drawn once', () => {
    const nested = small({
      nodes: [node('outer', 'loop'), ...small().nodes],
      edges: [{ id: 'o1', from: 'outer', to: 'loop', handle: 'loop' }, ...small().edges],
    })

    expect(isUnrolled(nested, 'loop')).toBe(false)
  })
})

describe('run kinds', () => {
  it('maps every drawn session, subflow children included, to its run kind', () => {
    const grandchild = flow({ sessionId: 's3', runKind: 'automation' })
    const parent = flow({
      runKind: 'conversation',
      children: [{ nodeId: 'call', count: 1, flow: flow({ sessionId: 's2', runKind: 'conversation', children: [{ nodeId: 'deep', count: 1, flow: grandchild }] }) }],
    })

    expect(runKindsOf(parent)).toEqual({ s1: 'conversation', s2: 'conversation', s3: 'automation' })
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
