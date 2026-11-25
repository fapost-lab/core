import { BRANCHING_TYPES, branchOwners, framesOutermostFirst, frameHandleOf, parentFrameOf } from '@builder/utils/graphFrames'

/**
 * The run graph of a flow session as a tree of boxes for ELK and Vue Flow: the definition the session executed, the path
 * it took lit, untaken nodes dimmed, branches and subflow children as nested frames. A small loop is unrolled into a
 * frame per pass (see {@link isUnrolled}); any other loop is drawn once with "×N" and a pass to colour the path by.
 * Pure: no layout here (runGraphLayout.ts) and no rendering.
 */

/** A loop with more passes than this is drawn once, with a pass switcher. */
export const UNROLL_MAX_PASSES = 5

/** A loop whose passes would draw more cards than this (passes × body nodes) is drawn once, with a pass switcher. */
export const UNROLL_MAX_CARDS = 20

export type RunStatus = 'done' | 'waiting' | 'failed' | 'running'

/** A node's status on the drawing: what it did, or `untaken` when the run (or the chosen pass) never reached it. */
export type DrawnStatus = RunStatus | 'untaken'

/** How a drawn status reads, as the kit's status tone. */
export const RUN_STATUS_TONES: Record<DrawnStatus, 'success' | 'info' | 'warning' | 'danger' | 'neutral'> = {
  done: 'success',
  waiting: 'warning',
  failed: 'danger',
  running: 'info',
  untaken: 'neutral',
}

/** The page's strings for the run graph (`console.flow_sessions.graph`). */
export interface RunGraphTranslations {
  title: string
  back: string
  description: string
  truncated: string
  empty: string
  statuses: Record<DrawnStatus, string>
  loop: { times: string; title: string; pass: string; previous: string; next: string }
  frames: { branch: string; subflow: string; runs: string; unnamed: string; pass: string }
  node: {
    title: string
    description: string
    events: Record<string, string>
    handle: string
    next: string
    duration: string
    duration_value: string
    iterations: string
    error: string
    empty: string
    loading: string
    failed: string
    data: string
    hide_data: string
    input: string
    output: string
    error_message: string
    payload_loading: string
    payload_failed: string
    retry: string
    payloads_hidden: string
    payloads_off: string
    payloads_conversation: string
  }
}

export interface RunGraphNodeRun {
  status: RunStatus
  visits: number
  handles: string[]
  durationMs: number | null
  errorClass: string | null
}

export interface RunGraphNode {
  id: string
  type: string
  version: number
  label: string | null
  typeLabel: string | null
  endStatus: string | null
  run: RunGraphNodeRun | null
}

export interface RunGraphEdge {
  id: string
  from: string
  to: string
  handle: string
}

export interface RunGraphPassNode {
  id: string
  status: RunStatus
  handle: string | null
}

export interface RunGraphLoop {
  nodeId: string
  passes: { pass: number; nodes: RunGraphPassNode[] }[]
}

export interface RunGraphChild {
  nodeId: string
  count: number
  flow: RunGraphFlow
}

/** What started a session (`FlowRunKind`): a conversation's trace never records node input and output. */
export type RunKind = 'conversation' | 'automation'

export interface RunGraphFlow {
  sessionId: string
  status: string
  runKind: RunKind
  flowName: string | null
  nodes: RunGraphNode[]
  edges: RunGraphEdge[]
  loops: RunGraphLoop[]
  children: RunGraphChild[]
}

export interface RunGraph {
  flow: RunGraphFlow
  truncated: boolean
  /** How many trace rows the server reads at most (RunGraphReader::TRACE_ROW_LIMIT). */
  limit: number
}

/** The pass chosen for each loop, by `<session id>:<loop node id>` (see {@link loopKey}); a loop not in it shows its last. */
export type PassSelection = Record<string, number>

/** A card on the drawing. */
export interface DrawnNode {
  kind: 'node'
  id: string
  parent: string | null
  sessionId: string
  node: RunGraphNode
  status: DrawnStatus
  /** For a loop node: how many passes it ran, and the index of the one shown. */
  loop: { key: string; count: number; index: number; pass: number | null } | null
  /** For a copy of a body node in an unrolled loop: the pass it shows, counted from 1; `null` for any other card. */
  pass: number | null
}

/** A frame on the drawing: a branch of a node (`branch`), a subflow child run (`subflow`) or a pass of an unrolled loop (`pass`). */
export interface DrawnFrame {
  kind: 'frame'
  id: string
  parent: string | null
  frame: 'branch' | 'subflow' | 'pass'
  /** The branch's handle, the child flow's name, or the pass's number counted from 1. */
  title: string | null
  /** A subflow node's runs, of which the latest is drawn. */
  count: number
  status: DrawnStatus
}

export interface DrawnEdge {
  id: string
  source: string
  target: string
  handle: string
  taken: boolean
}

export interface DrawnGraph {
  /** Parents come before their children. */
  boxes: (DrawnNode | DrawnFrame)[]
  edges: DrawnEdge[]
}

export function nodeKey(sessionId: string, nodeId: string): string {
  return `${sessionId}:${nodeId}`
}

export function loopKey(sessionId: string, loopNodeId: string): string {
  return `${sessionId}:${loopNodeId}`
}

/**
 * Lays the run out as boxes and edges: every node of each flow once, branch frames around what a branching node's
 * non-default handle leads to, a subflow child's flow in a frame next to the node that started it, and the body of a
 * small loop once per pass, in a frame of that pass next to the loop node.
 */
export function drawRunGraph(graph: RunGraph, selection: PassSelection = {}): DrawnGraph {
  const boxes: (DrawnNode | DrawnFrame)[] = []
  const edges: DrawnEdge[] = []

  drawFlow(graph.flow, null, selection, boxes, edges)

  return { boxes, edges }
}

function drawFlow(flow: RunGraphFlow, parent: string | null, selection: PassSelection, boxes: (DrawnNode | DrawnFrame)[], edges: DrawnEdge[]): void {
  const unrolled = unrolledLoops(flow)
  const unrolledBody = new Set([...unrolled.values()].flatMap((body) => [...body]))
  // The body of an unrolled loop is drawn in its pass frames only; the rest of the flow is framed without it.
  const outer: RunGraphFlow = {
    ...flow,
    nodes: flow.nodes.filter((node) => !unrolledBody.has(node.id)),
    edges: flow.edges.filter((edge) => !unrolledBody.has(edge.from) && !unrolledBody.has(edge.to)),
  }
  const statuses = statusesFor(flow, selection)
  const owners = branchOwners(outer)
  const nodesById = new Map(outer.nodes.map((node) => [node.id, node]))

  // Frames first, parents before children: a frame sits in the frame its branching node sits in.
  const frameIds = outer.nodes.map((node) => owners.get(node.id)).filter((frame): frame is string => frame !== undefined)

  for (const frameId of framesOutermostFirst(frameIds, owners)) {
    const members = outer.nodes.filter((node) => owners.get(node.id) === frameId)
    const owner = parentFrameOf(frameId, owners)
    const frame: DrawnFrame = {
      kind: 'frame',
      id: frameBoxId(flow.sessionId, frameId),
      parent: owner === undefined ? parent : frameBoxId(flow.sessionId, owner),
      frame: 'branch',
      title: frameHandleOf(frameId),
      count: 1,
      status: members.some((node) => statuses.get(node.id) !== 'untaken') ? 'done' : 'untaken',
    }

    boxes.push(frame)
  }

  for (const node of outer.nodes) {
    const owner = owners.get(node.id)
    const loop = flow.loops.find((candidate) => candidate.nodeId === node.id)
    const key = loopKey(flow.sessionId, node.id)
    const index = loop ? passIndex(loop, selection[key]) : -1

    boxes.push({
      kind: 'node',
      id: nodeKey(flow.sessionId, node.id),
      parent: owner === undefined ? parent : frameBoxId(flow.sessionId, owner),
      sessionId: flow.sessionId,
      node,
      status: statuses.get(node.id) ?? 'untaken',
      loop: node.type === 'loop' ? { key, count: loop?.passes.length ?? 0, index: Math.max(index, 0), pass: loop?.passes[index]?.pass ?? null } : null,
      pass: null,
    })
  }

  for (const [loopId, body] of unrolled) {
    const owner = owners.get(loopId)

    drawPasses(flow, loopId, body, owner === undefined ? parent : frameBoxId(flow.sessionId, owner), boxes, edges)
  }

  const handlesTaken = takenHandles(flow, selection)

  for (const edge of outer.edges) {
    if (!nodesById.has(edge.from) || !nodesById.has(edge.to)) {
      continue
    }

    edges.push({
      id: `${flow.sessionId}:${edge.id}`,
      source: nodeKey(flow.sessionId, edge.from),
      target: nodeKey(flow.sessionId, edge.to),
      handle: edge.handle,
      taken: (handlesTaken.get(edge.from)?.has(edge.handle) ?? false) && statuses.get(edge.to) !== 'untaken',
    })
  }

  for (const child of flow.children) {
    if (!nodesById.has(child.nodeId)) {
      continue
    }

    const owner = owners.get(child.nodeId)
    const frame: DrawnFrame = {
      kind: 'frame',
      id: `subflow:${child.flow.sessionId}`,
      parent: owner === undefined ? parent : frameBoxId(flow.sessionId, owner),
      frame: 'subflow',
      title: child.flow.flowName,
      count: child.count,
      status: child.flow.nodes.some((node) => node.run !== null) ? 'done' : 'untaken',
    }

    boxes.push(frame)
    edges.push({
      id: `${flow.sessionId}:${child.nodeId}->${frame.id}`,
      source: nodeKey(flow.sessionId, child.nodeId),
      target: frame.id,
      handle: 'subflow',
      taken: frame.status !== 'untaken',
    })

    drawFlow(child.flow, frame.id, selection, boxes, edges)
  }
}

/**
 * An unrolled loop's passes: a frame per pass, in pass order, holding a copy of every body node with what it did in
 * that pass, the body's edges lit by that pass's handles, and an edge from the loop node's `loop` handle into the frame.
 */
function drawPasses(flow: RunGraphFlow, loopId: string, body: Set<string>, parent: string | null, boxes: (DrawnNode | DrawnFrame)[], edges: DrawnEdge[]): void {
  const passes = flow.loops.find((loop) => loop.nodeId === loopId)?.passes ?? []
  const bodyNodes = flow.nodes.filter((node) => body.has(node.id))
  const bodyEdges = flow.edges.filter((edge) => body.has(edge.from) && body.has(edge.to))

  passes.forEach((pass, index) => {
    const number = index + 1
    const frameId = `pass:${flow.sessionId}:${loopId}:${number}`
    const shown = new Map(pass.nodes.map((node) => [node.id, node]))
    const statusOf = (id: string): DrawnStatus => shown.get(id)?.status ?? 'untaken'

    boxes.push({ kind: 'frame', id: frameId, parent, frame: 'pass', title: String(number), count: 1, status: pass.nodes.length > 0 ? 'done' : 'untaken' })

    for (const node of bodyNodes) {
      boxes.push({
        kind: 'node',
        id: passNodeKey(flow.sessionId, node.id, number),
        parent: frameId,
        sessionId: flow.sessionId,
        node,
        status: statusOf(node.id),
        loop: null,
        pass: number,
      })
    }

    for (const edge of bodyEdges) {
      edges.push({
        id: `${flow.sessionId}:${edge.id}@${number}`,
        source: passNodeKey(flow.sessionId, edge.from, number),
        target: passNodeKey(flow.sessionId, edge.to, number),
        handle: edge.handle,
        taken: shown.get(edge.from)?.handle === edge.handle && statusOf(edge.to) !== 'untaken',
      })
    }

    edges.push({
      id: `${flow.sessionId}:${loopId}->${frameId}`,
      source: nodeKey(flow.sessionId, loopId),
      target: frameId,
      handle: 'loop',
      taken: pass.nodes.length > 0,
    })
  })
}

function passNodeKey(sessionId: string, nodeId: string, pass: number): string {
  return `${nodeKey(sessionId, nodeId)}@${pass}`
}

/**
 * The body of each loop node of a flow, the way the server cuts it (RunGraphReader::loopBodies()): what the loop's
 * `loop` handle leads to, following every edge, never back through the loop node itself.
 */
export function loopBodies(flow: RunGraphFlow): Map<string, Set<string>> {
  const bodies = new Map<string, Set<string>>()

  for (const loop of flow.nodes.filter((node) => node.type === 'loop')) {
    const body = new Set<string>()
    const queue = flow.edges.filter((edge) => edge.from === loop.id && edge.handle === 'loop').map((edge) => edge.to)

    while (queue.length > 0) {
      const id = queue.shift()!

      if (id === loop.id || body.has(id)) {
        continue
      }

      body.add(id)
      queue.push(...flow.edges.filter((edge) => edge.from === id).map((edge) => edge.to))
    }

    bodies.set(loop.id, body)
  }

  return bodies
}

/**
 * Whether a loop is drawn unrolled, a frame per pass, rather than once with a pass switcher: only a small, simple one.
 * It ran 1 to {@link UNROLL_MAX_PASSES} passes, its passes draw at most {@link UNROLL_MAX_CARDS} cards, its body holds
 * no loop, no branching node and no subflow, it sits in no other loop's body, and nothing outside the body leads into
 * it but the loop's own `loop` handle. Reads the structure and the pass count only, never the chosen pass, so the
 * canvas lays it out once; a live loop that outgrows the limits folds back into one card with a switcher.
 */
export function isUnrolled(flow: RunGraphFlow, loopId: string, bodies: Map<string, Set<string>> = loopBodies(flow)): boolean {
  const body = bodies.get(loopId)
  const count = flow.loops.find((loop) => loop.nodeId === loopId)?.passes.length ?? 0

  if (body === undefined || body.size === 0 || count < 1 || count > UNROLL_MAX_PASSES || count * body.size > UNROLL_MAX_CARDS) {
    return false
  }

  const types = new Map(flow.nodes.map((node) => [node.id, node.type]))
  const children = new Set(flow.children.map((child) => child.nodeId))

  for (const id of body) {
    const type = types.get(id) ?? ''

    if (type === 'loop' || type === 'subflow' || BRANCHING_TYPES.has(type) || children.has(id)) {
      return false
    }
  }

  for (const [otherId, other] of bodies) {
    if (otherId !== loopId && other.has(loopId)) {
      return false
    }
  }

  return flow.edges.every((edge) => !body.has(edge.to) || body.has(edge.from) || (edge.from === loopId && edge.handle === 'loop'))
}

/** The unrolled loops of a flow (see {@link isUnrolled}) with their bodies, by loop node id. */
function unrolledLoops(flow: RunGraphFlow): Map<string, Set<string>> {
  const bodies = loopBodies(flow)

  return new Map([...bodies].filter(([loopId]) => isUnrolled(flow, loopId, bodies)))
}

function frameBoxId(sessionId: string, frameId: string): string {
  return `branch:${sessionId}:${frameId}`
}

/** The index into a loop's passes the drawing shows: the chosen one when it exists, else the last. */
export function passIndex(loop: RunGraphLoop, chosen: number | undefined): number {
  if (chosen !== undefined && chosen >= 0 && chosen < loop.passes.length) {
    return chosen
  }

  return loop.passes.length - 1
}

/**
 * Each node's drawn status: its run status, except inside a loop with passes, where the chosen pass decides — a body
 * node the pass never reached is `untaken` there.
 */
export function statusesFor(flow: RunGraphFlow, selection: PassSelection): Map<string, DrawnStatus> {
  const statuses = new Map<string, DrawnStatus>(flow.nodes.map((node) => [node.id, node.run?.status ?? 'untaken']))

  for (const loop of flow.loops) {
    const index = passIndex(loop, selection[loopKey(flow.sessionId, loop.nodeId)])
    const body = new Set(loop.passes.flatMap((pass) => pass.nodes.map((node) => node.id)))
    const shown = new Map((loop.passes[index]?.nodes ?? []).map((node) => [node.id, node.status]))

    for (const id of body) {
      if (statuses.has(id)) {
        statuses.set(id, shown.get(id) ?? 'untaken')
      }
    }
  }

  return statuses
}

function takenHandles(flow: RunGraphFlow, selection: PassSelection): Map<string, Set<string>> {
  const taken = new Map<string, Set<string>>(flow.nodes.map((node) => [node.id, new Set(node.run?.handles ?? [])]))

  for (const loop of flow.loops) {
    const index = passIndex(loop, selection[loopKey(flow.sessionId, loop.nodeId)])
    const body = new Set(loop.passes.flatMap((pass) => pass.nodes.map((node) => node.id)))

    for (const id of body) {
      taken.set(id, new Set())
    }

    for (const node of loop.passes[index]?.nodes ?? []) {
      if (node.handle !== null) {
        taken.get(node.id)?.add(node.handle)
      }
    }
  }

  return taken
}

/** Every loop of the run, its own flow's and its subflow children's, for the pass switchers; an unrolled loop needs none. */
export function loopsOf(flow: RunGraphFlow): { key: string; sessionId: string; node: RunGraphNode | null; count: number; unrolled: boolean }[] {
  const bodies = loopBodies(flow)
  const own = flow.loops.map((loop) => ({
    key: loopKey(flow.sessionId, loop.nodeId),
    sessionId: flow.sessionId,
    node: flow.nodes.find((node) => node.id === loop.nodeId) ?? null,
    count: loop.passes.length,
    unrolled: isUnrolled(flow, loop.nodeId, bodies),
  }))

  return [...own, ...flow.children.flatMap((child) => loopsOf(child.flow))]
}

/** The run kind of every session drawn in a flow — the flow's own and its subflow children's, by session id. */
export function runKindsOf(flow: RunGraphFlow): Record<string, RunKind> {
  const kinds: Record<string, RunKind> = { [flow.sessionId]: flow.runKind }

  for (const child of flow.children) {
    Object.assign(kinds, runKindsOf(child.flow))
  }

  return kinds
}
