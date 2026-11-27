// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { router } from '@inertiajs/vue3'
import FlowRunsCard from './FlowRunsCard.vue'
import type { FlowRuns } from './types'

const { page } = vi.hoisted(() => ({
  page: {
    props: {
      locale: 'en',
      translations: {
        console: {
          admin_dashboard: {
            flow_runs: {
              title: 'Runs per flow',
              description: 'Runs per flow.',
              period: 'Period',
              periods: { '1h': 'Last hour', '24h': 'Last 24 hours', '7d': 'Last 7 days', '30d': 'Last 30 days' },
              flow: 'Flow',
              started: 'Started',
              completed: 'Completed',
              failed: 'Failed',
              unnamed: 'Untitled flow',
              unattributed: 'Runs no longer on record',
              unattributed_hint: 'Sessions removed.',
              more: 'Showing the :shown busiest of :total flows.',
              empty: 'No runs.',
            },
          },
        },
      },
    },
  },
}))

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  usePage: () => page,
}))

function runs(overrides: Partial<FlowRuns> = {}): FlowRuns {
  return {
    flows: [{ flow_id: 'f1', name: 'Orders', started: 1200, completed: 1100, failed: 3 }],
    total: 1,
    unattributed: { started: 0, completed: 0, failed: 0 },
    period: '24h',
    default: '24h',
    param: 'runs_period',
    periods: ['1h', '24h', '7d', '30d'],
    ...overrides,
  }
}

describe('FlowRunsCard', () => {
  it('lists each flow with its figures, and no unattributed row while it is all zeros', () => {
    const wrapper = mount(FlowRunsCard, { props: { runs: runs() } })

    expect(wrapper.get('[data-test="flow-runs-f1"]').text()).toContain('Orders')
    expect(wrapper.get('[data-test="flow-runs-f1"]').text()).toContain('1,200')
    expect(wrapper.find('[data-test="flow-runs-unattributed"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="flow-runs-more"]').exists()).toBe(false)
  })

  it('shows the runs whose session is gone, and how many flows are left out', () => {
    const wrapper = mount(FlowRunsCard, {
      props: { runs: runs({ total: 14, unattributed: { started: 2, completed: 1, failed: 0 } }) },
    })

    expect(wrapper.get('[data-test="flow-runs-unattributed"]').text()).toContain('Runs no longer on record')
    expect(wrapper.get('[data-test="flow-runs-more"]').text()).toBe('Showing the 1 busiest of 14 flows.')
  })

  it('says so when nothing ran, and names a flow without a name', () => {
    expect(mount(FlowRunsCard, { props: { runs: runs({ flows: [], total: 0, unattributed: null }) } }).find('[data-test="flow-runs-empty"]').exists()).toBe(true)

    const unnamed = mount(FlowRunsCard, { props: { runs: runs({ flows: [{ flow_id: 'f2', name: '', started: 1, completed: 0, failed: 0 }] }) } })
    expect(unnamed.get('[data-test="flow-runs-f2"]').text()).toContain('Untitled flow')
  })

  describe('choosing a period', () => {
    afterEach(() => {
      vi.restoreAllMocks()
      window.history.replaceState(null, '', '/')
    })

    function choose(period: string): ReturnType<typeof vi.spyOn> {
      const get = vi.spyOn(router, 'get').mockImplementation(() => undefined)
      const wrapper = mount(FlowRunsCard, { props: { runs: runs() } })
      ;(wrapper.vm as unknown as { $: { setupState: { choose: (value: unknown) => void } } }).$.setupState.choose(period)

      return get
    }

    it('reloads only the card with the period in the address, keeping the other parameters', () => {
      window.history.replaceState(null, '', '/admin?tab=x')
      const get = choose('7d')

      expect(get).toHaveBeenCalledOnce()
      const [url, data, options] = get.mock.calls[0] as [string, Record<string, string>, { only: string[] }]
      expect(url).toBe('/admin')
      expect(data).toEqual({ tab: 'x', runs_period: '7d' })
      expect(options.only).toEqual(['flowRuns'])
    })

    it('drops the parameter for the default period the server sent', () => {
      window.history.replaceState(null, '', '/admin?runs_period=7d')
      const get = choose('24h')

      expect(get.mock.calls[0][1]).toEqual({})
    })
  })
})
