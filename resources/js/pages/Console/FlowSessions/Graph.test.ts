// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { computed, defineComponent, h } from 'vue'
import { drawRunGraph, type DrawnNode, type RunGraph, type RunKind } from '@console/run-graph/runGraph'
import Graph from './Graph.vue'

const { page, canvas } = vi.hoisted(() => ({
  page: { props: {} as Record<string, unknown>, url: '/assistant/a/flow-sessions/s1/graph' },
  canvas: { select: null as null | ((box: unknown) => void) },
}))

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  usePage: () => page,
  Head: defineComponent({ setup: () => () => null }),
}))

vi.mock('@console/lib/useLiveUpdates', () => ({
  useLiveUpdates: () => ({ transport: computed(() => 'polling') }),
}))

// The real canvas loads Vue Flow and ELK; the page only needs the node it reports as selected.
vi.mock('@console/run-graph/RunGraphCanvas.vue', () => ({
  __esModule: true,
  default: defineComponent({
    emits: ['select'],
    setup(_, { emit }) {
      canvas.select = (box) => emit('select', box)

      return () => h('div')
    },
  }),
}))

const node = {
  title: 'Node',
  description: 'The latest visits of the node.',
  events: { node_entered: 'Entered', node_completed: 'Completed', node_failed: 'Failed' },
  handle: 'Exit',
  next: 'Next node',
  duration: 'Duration',
  duration_value: ':value ms',
  iterations: 'Loop pass',
  error: 'Error',
  empty: 'The run did not reach this node.',
  loading: 'Loading…',
  failed: 'The visits could not be loaded.',
  data: 'Show data',
  hide_data: 'Hide data',
  input: 'Input',
  output: 'Output',
  error_message: 'Error message',
  payload_loading: 'Loading the data…',
  payload_failed: 'The data could not be loaded.',
  retry: 'Retry',
  payloads_hidden: 'Node data is visible with the “View run payloads” permission.',
  payloads_off: 'No data was recorded: recording node input and output was off.',
  payloads_conversation: 'Conversations do not record node input and output.',
}

const translations = {
  console: {
    flow_sessions: {
      statuses: { failed: 'Failed', completed: 'Completed', active: 'Active' },
      live: { live: 'Live', polling: 'Polling' },
      view: { empty_value: '—' },
      graph: {
        title: 'Run graph',
        back: 'Session',
        description: 'What the run did.',
        truncated: 'Only the first :count rows.',
        empty: 'Nothing to draw.',
        statuses: { done: 'Done', waiting: 'Waiting', failed: 'Failed', running: 'Running', untaken: 'Not reached' },
        loop: { title: 'Loop passes', pass: 'Pass :pass of :count', previous: 'Previous pass', next: 'Next pass' },
        node,
      },
    },
  },
}

const PAYLOAD_TEMPLATE = '/assistant/a/flow-sessions/s1/graph/visits/{entry}'

function graphOf(runKind: RunKind): RunGraph {
  return {
    truncated: false,
    limit: 20_000,
    flow: {
      sessionId: 's1',
      status: 'failed',
      runKind,
      flowName: 'Orders',
      nodes: [{ id: 'call', type: 'call', version: 1, label: 'Call the CRM', typeLabel: null, endStatus: null, run: { status: 'failed', visits: 2, handles: [], durationMs: 1, errorClass: 'RuntimeException' } }],
      edges: [],
      loops: [],
      children: [],
    },
  }
}

function visit(id: string, event: string, hasData?: boolean): Record<string, unknown> {
  return {
    id,
    event,
    at: null,
    status: event === 'node_failed' ? 'failed' : 'done',
    handle: null,
    next: null,
    durationMs: null,
    iterations: {},
    errorClass: null,
    ...(hasData === undefined ? {} : { hasData }),
  }
}

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

/** Answers the visits request with `visits` and each payload request with the next of `payloads`. */
function serve(visits: Record<string, unknown>[], ...payloads: (Response | Promise<Response>)[]): ReturnType<typeof vi.fn> {
  const fetch = vi.fn((url: string) => {
    if (url.startsWith('/assistant/a/flow-sessions/s1/graph/visits?')) {
      return Promise.resolve(json({ visits }))
    }

    const next = payloads.shift()

    return next === undefined ? Promise.reject(new Error(`unexpected ${url}`)) : Promise.resolve(next)
  })

  vi.stubGlobal('fetch', fetch)

  return fetch
}

async function openNode(runKind: RunKind, canViewPayloads = true): Promise<void> {
  const graph = graphOf(runKind)

  mount(Graph, {
    attachTo: document.body,
    props: {
      session: { id: 's1', status: 'failed', flowName: 'Orders', isLive: false },
      graph,
      live: null,
      can: { viewPayloads: canViewPayloads },
      urls: {
        session: '/assistant/a/flow-sessions/s1',
        visits: '/assistant/a/flow-sessions/s1/graph/visits',
        payload: canViewPayloads ? { template: PAYLOAD_TEMPLATE, placeholder: '{entry}' } : null,
      },
    },
  })

  // The canvas is an async component: it mounts after its import resolves.
  await flushPromises()
  await vi.dynamicImportSettled()
  await flushPromises()

  const box = drawRunGraph(graph).boxes.find((candidate): candidate is DrawnNode => candidate.kind === 'node' && candidate.node.id === 'call')
  canvas.select?.(box)
  await flushPromises()
}

const text = (): string => document.body.textContent ?? ''

function buttons(label: string): HTMLButtonElement[] {
  return [...document.body.querySelectorAll('button')].filter((button) => button.textContent?.trim() === label)
}

beforeEach(() => {
  page.props = { translations }
  canvas.select = null
})

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('the node panel of the run graph', () => {
  it('tells a user without View run payloads where the data is, and offers no data', async () => {
    serve([visit('v1', 'node_completed')])

    await openNode('automation', false)

    expect(text()).toContain(node.payloads_hidden)
    expect(text()).toContain('Completed')
    expect(buttons(node.data)).toHaveLength(0)
    expect(text()).not.toContain(node.payloads_off)
  })

  it('offers data only on a visit that carries it, and says why a completed automation visit has none', async () => {
    serve([visit('v1', 'node_completed', true), visit('v2', 'node_completed', false), visit('v3', 'node_failed', false)])

    await openNode('automation')

    expect(text()).not.toContain(node.payloads_hidden)
    expect(buttons(node.data)).toHaveLength(1)
    // The note is for the completed visit without data, not for the failed one.
    expect(text().split(node.payloads_off)).toHaveLength(2)
  })

  it('loads a visit’s data from the URL template, shows it while loading and hides it again', async () => {
    let answer: (response: Response) => void = () => {}
    const fetch = serve([visit('v-1', 'node_completed', true)], new Promise<Response>((resolve) => (answer = resolve)))

    await openNode('automation')
    buttons(node.data)[0].click()
    await flushPromises()

    expect(fetch).toHaveBeenLastCalledWith('/assistant/a/flow-sessions/s1/graph/visits/v-1?session=s1', expect.objectContaining({ credentials: 'same-origin' }))
    expect(text()).toContain(node.payload_loading)

    answer(json({ input: { message: { text: 'hi' } }, output: { state_changes: { plan: 'pro' } }, errorMessage: null }))
    await flushPromises()

    expect(text()).not.toContain(node.payload_loading)
    expect(text()).toContain(node.input)
    expect(text()).toContain('"text": "hi"')
    expect(text()).toContain(node.output)
    expect(text()).toContain('"plan": "pro"')
    expect(text()).not.toContain(node.error_message)
    expect(text()).not.toContain(node.payloads_off)

    buttons(node.hide_data)[0].click()
    await flushPromises()

    expect(text()).not.toContain(node.input)
    expect(buttons(node.data)).toHaveLength(1)
  })

  it('offers a retry when the data could not be loaded', async () => {
    serve([visit('v1', 'node_completed', true)], json({ message: 'Server Error' }, 500), json({ input: null, output: 'done', errorMessage: null }))

    await openNode('automation')
    buttons(node.data)[0].click()
    await flushPromises()

    expect(text()).toContain(node.payload_failed)

    buttons(node.retry)[0].click()
    await flushPromises()

    expect(text()).not.toContain(node.payload_failed)
    expect(text()).toContain(node.output)
    expect(text()).toContain('done')
  })

  it('says recording was off when a completed automation visit carries only an error message', async () => {
    serve([visit('v1', 'node_completed', true)], json({ input: null, output: null, errorMessage: 'Timeout' }))

    await openNode('automation')
    buttons(node.data)[0].click()
    await flushPromises()

    expect(text()).toContain(node.error_message)
    expect(text()).toContain('Timeout')
    expect(text()).toContain(node.payloads_off)
  })

  it('says a conversation records no input and output on any of its visits, a failed one included', async () => {
    serve([visit('v1', 'node_failed', true), visit('v2', 'node_entered', false)], json({ input: null, output: null, errorMessage: 'Timeout' }))

    await openNode('conversation')

    // The visit without data says it straight away.
    expect(text().split(node.payloads_conversation)).toHaveLength(2)

    buttons(node.data)[0].click()
    await flushPromises()

    expect(text()).toContain('Timeout')
    expect(text().split(node.payloads_conversation)).toHaveLength(3)
    expect(text()).not.toContain(node.payloads_off)
  })
})
