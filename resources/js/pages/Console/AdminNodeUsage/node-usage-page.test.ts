// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import Index from './Index.vue'
import type { NodeUsagePageData, NodeUsageRow } from './types'

const { page } = vi.hoisted(() => ({
  page: {
    props: {
      locale: 'en',
      translations: {
        console: {
          node_usage: {
            navigation: 'Node usage',
            title: 'Node usage',
            description: 'Which node types…',
            period: 'Period',
            periods: { '1h': 'Last hour', '24h': 'Last 24 hours', '7d': 'Last 7 days', '30d': 'Last 30 days' },
            window: 'Runs are counted from the flow log.',
            runs_hidden: 'Run figures are shown to staff who may view the flow log.',
            scoped: 'Counted for the assistants you have access to.',
            nodes: { title: 'Node types', description: 'Each node type.', empty: 'No published flow uses a node yet.' },
            actions: { title: 'Actions', description: 'Actions.', empty: 'No published flow calls an action.' },
            unused: { title: 'Node types no flow uses', description: 'Registered node types.' },
            columns: {
              node: 'Node type',
              action: 'Action',
              active_flows: 'Active flows',
              active_nodes: 'Nodes',
              versions: 'Versions',
              executions: 'Runs',
              failures: 'Failed',
              last_run: 'Last run',
            },
            hints: { active_flows: 'Flows', active_nodes: 'Nodes', versions: 'Versions' },
            core: 'Core',
            solution: 'Solution :id',
            missing: 'No handler',
            orphaned: 'No handler — flows fail at this node',
            never: '—',
          },
        },
      },
    },
  },
}))

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  usePage: () => page,
  Head: defineComponent({ setup: () => () => null }),
}))

function node(overrides: Partial<NodeUsageRow> = {}): NodeUsageRow {
  return {
    key: 'send_message@1',
    type: 'send_message',
    version: 1,
    label: 'Send message',
    solution: null,
    registered: true,
    orphaned: false,
    activeFlows: 3,
    activeNodes: 5,
    versions: 4,
    executions: 1200,
    failures: 2,
    lastRunAt: '2026-11-20T10:00:00+00:00',
    ...overrides,
  }
}

function data(overrides: Partial<NodeUsagePageData> = {}): NodeUsagePageData {
  return {
    nodes: [node()],
    actions: [],
    unused: [],
    runs: true,
    period: '30d',
    default: '30d',
    param: 'period',
    periods: ['1h', '24h', '7d', '30d'],
    ...overrides,
  }
}

describe('AdminNodeUsage', () => {
  it('lists each node type with its figures and runs', () => {
    const wrapper = mount(Index, { props: data() })
    const row = wrapper.get('[data-test="node-send_message@1"]').text()

    expect(row).toContain('Send message')
    expect(row).toContain('send_message@1')
    expect(row).toContain('1,200')
    expect(wrapper.find('[data-test="node-usage-period"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="node-usage-scoped"]').exists()).toBe(false)
  })

  it('marks a node without a handler, and one that breaks an active flow', () => {
    const wrapper = mount(Index, {
      props: data({
        nodes: [
          node({ key: 'old@1', type: 'old', label: null, registered: false, orphaned: false, activeFlows: 0, activeNodes: 0 }),
          node({ key: 'gone@2', type: 'gone', label: null, registered: false, orphaned: true }),
        ],
      }),
    })

    expect(wrapper.get('[data-test="node-old@1"]').find('[data-test="node-missing"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="node-gone@2"]').find('[data-test="node-orphaned"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="node-gone@2"]').find('[data-test="node-missing"]').exists()).toBe(false)
  })

  it('shows no run columns or period to a user who may not view the flow log', () => {
    const wrapper = mount(Index, {
      props: data({ runs: false, nodes: [node({ executions: null, failures: null, lastRunAt: null })] }),
    })

    expect(wrapper.find('[data-test="node-usage-period"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="node-usage-window"]').text()).toBe('Run figures are shown to staff who may view the flow log.')
    expect(wrapper.get('[data-test="node-usage-nodes"]').text()).not.toContain('Runs')
  })

  it('lists actions with their owner, and says when a user sees only some assistants', () => {
    const wrapper = mount(Index, {
      props: data({
        unused: null,
        actions: [
          { id: 'crm.sync', solution: 'crm', registered: true, activeFlows: 1, versions: 2, executions: 4, failures: 0, lastRunAt: null },
          { id: 'gone.action', solution: null, registered: false, activeFlows: 1, versions: 1, executions: 0, failures: 0, lastRunAt: null },
        ],
      }),
    })

    expect(wrapper.get('[data-test="action-crm.sync"]').text()).toContain('Solution crm')
    expect(wrapper.get('[data-test="action-gone.action"]').find('[data-test="action-missing"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="node-usage-scoped"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="node-usage-unused"]').exists()).toBe(false)
  })

  it('says so when no flow uses a node or an action', () => {
    const wrapper = mount(Index, { props: data({ nodes: [], actions: [], unused: [{ type: 'delay', label: 'Delay' }] }) })

    expect(wrapper.find('[data-test="node-usage-nodes-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="node-usage-actions-empty"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="node-usage-unused"]').text()).toContain('Delay')
  })
})
