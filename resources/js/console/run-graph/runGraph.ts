/**
 * The run graph of a flow session as a tree of boxes for ELK and Vue Flow: the definition the session executed, the path
 * it took lit, untaken nodes dimmed, branches and subflow children as nested frames, loops drawn once with "×N" and a
 * pass to colour the path by. Pure: no layout here (runGraphLayout.ts) and no rendering.
 */

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
  frames: { branch: string; subflow: string; runs: string; unnamed: string }
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

export interface RunGraphFlow {
  sessionId: string
  status: string
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
}

/** A frame on the drawing: a branch of a node (`branch`) or a subflow child run (`subflow`). */
export interface DrawnFrame {
  kind: 'frame'
  id: string
  parent: string | null
  frame: 'branch' | 'subflow'
  /** The branch's handle, or the child flow's name. */
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

/** Node types whose non-default handles open a branch frame (rules of a condition, buttons of a message). */
const BRANCHING_TYPES = new Set(['condition', 'branch', 'send_message'])

export function nodeKey(sessionId: string, nodeId: string): string {
  return `${sessionId}:${nodeId}`
}

export function loopKey(sessionId: string, loopNodeId: string): string {
  return `${sessionId}:${loopNodeId}`
}

/**
 * Lays the run out as boxes and edges: every node of each flow once, branch frames around what a branching node's
 * non-default handle leads to, a subflow child's flow in a frame next to the node that started it.
 */
export function drawRunGraph(graph: RunGraph, selection: PassSelection = {}): DrawnGraph {
  const boxes: (DrawnNode | DrawnFrame)[] = []
  const edges: DrawnEdge[] = []

  drawFlow(graph.flow, null, selection, boxes, edges)

  return { boxes, edges }
}

function drawFlow(flow: RunGraphFlow, parent: string | null, selection: PassSelection, boxes: (DrawnNode | DrawnFrame)[], edges: DrawnEdge[]): void {
  const statuses = statusesFor(flow, selection)
  const owners = branchOwners(flow)
  const nodesById = new Map(flow.nodes.map((node) => [node.id, node]))

  // Frames first, parents before children: a frame sits in the frame its branching node sits in.
  const frameIds = [...new Set(flow.nodes.map((node) => owners.get(node.id)).filter((frame): frame is string => frame !== undefined))]
  const parentFrame = (frameId: string): string | undefined => owners.get(branchingNodeOf(frameId))
  const depth = (frameId: string): number => {
    let level = 0

    for (let current = parentFrame(frameId); current !== undefined && level < 100; current = parentFrame(current)) {
      level++
    }

    return level
  }

  for (const frameId of [...frameIds].sort((a, b) => depth(a) - depth(b))) {
    const members = flow.nodes.filter((node) => owners.get(node.id) === frameId)
    const owner = parentFrame(frameId)
    const frame: DrawnFrame = {
      kind: 'frame',
      id: frameBoxId(flow.sessionId, frameId),
      parent: owner === undefined ? parent : frameBoxId(flow.sessionId, owner),
      frame: 'branch',
      title: frameId.slice(frameId.indexOf('|') + 1),
      count: 1,
      status: members.some((node) => statuses.get(node.id) !== 'untaken') ? 'done' : 'untaken',
    }

    boxes.push(frame)
  }

  for (const node of flow.nodes) {
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
    })
  }

  const handlesTaken = takenHandles(flow, selection)

  for (const edge of flow.edges) {
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

function frameBoxId(sessionId: string, frameId: string): string {
  return `branch:${sessionId}:${frameId}`
}

/** A branch frame's id is `<branching node id>|<handle>`; node ids never hold a `|`. */
function branchingNodeOf(frameId: string): string {
  return frameId.slice(0, frameId.indexOf('|'))
}

/**
 * The branch frame each node sits in, as `<branching node id>|<handle>`; a node outside any branch has none. A node
 * reached twice (a merge) stays where it was reached first, from the roots down.
 */
export function branchOwners(flow: RunGraphFlow): Map<string, string> {
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
      const opensBranch = BRANCHING_TYPES.has(types.get(id) ?? '') && edge.handle !== 'default'

      stack.push({ id: edge.to, owner: opensBranch ? `${id}|${edge.handle}` : owner })
    }
  }

  return owners
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

/** Every loop of the run, its own flow's and its subflow children's, for the pass switchers. */
export function loopsOf(flow: RunGraphFlow): { key: string; sessionId: string; node: RunGraphNode | null; count: number }[] {
  const own = flow.loops.map((loop) => ({
    key: loopKey(flow.sessionId, loop.nodeId),
    sessionId: flow.sessionId,
    node: flow.nodes.find((node) => node.id === loop.nodeId) ?? null,
    count: loop.passes.length,
  }))

  return [...own, ...flow.children.flatMap((child) => loopsOf(child.flow))]
}
