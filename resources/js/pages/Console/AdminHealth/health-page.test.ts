// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import Index from './Index.vue'
import type { GatewayCard, HealthReportData, QueuesCard, ReverbCard } from './types'

const { page, reload, cancelAll } = vi.hoisted(() => ({
  page: { props: {} as Record<string, unknown>, url: '/admin/health' },
  reload: vi.fn(),
  cancelAll: vi.fn(),
}))

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  usePage: () => page,
  router: { reload, cancelAll },
  Head: defineComponent({ setup: () => () => null }),
}))

const translations = {
  console: {
    health: {
      navigation: 'Health',
      title: 'Installation health',
      description: 'Whether live updates work.',
      state: { up: 'Up', down: 'Down', unknown: 'Unknown', disabled: 'Switched off', loading: 'Checking' },
      summary: { up: 'Working', down: 'Reported a problem', unknown: 'Could not find out', disabled: 'Switched off on purpose', loading: 'Asking' },
      hint: { down: 'It said it is not healthy.', unknown: 'Nothing is known.', disabled: 'Nothing is wrong.' },
      reverb: { title: 'Live updates', subtitle: 'Reverb', description: 'Websocket.', disabled: 'Console screens refresh by polling instead.', connections: 'Connections', channels: 'Channels', address: 'Address' },
      gateway: {
        title: 'Webhook gateway',
        subtitle: 'Gateway',
        description: 'Webhooks.',
        disabled: 'The application receives webhooks itself.',
        address: 'Address',
        specs: 'Published specifications',
        specs_none: 'Could not be read',
        drift: 'Channels on the old address',
        drift_none: 'Could not be counted',
        drift_zero: 'None.',
        drift_some: 'Still on the old address.',
        spec_status: { matches: 'In sync', missing: 'Not published', differs: 'Out of date' },
      },
      queues: {
        title: 'Queues',
        subtitle: 'Horizon',
        description: 'Background workers.',
        disabled: 'Queues do not run through Horizon.',
        failed: 'Failed jobs in the last :hours hours',
        failed_any: 'Recently failed jobs',
        supervisors: 'Horizon instances',
        queue: 'Queue',
        wait: 'Wait',
        per_minute: 'Jobs per minute',
        seconds: ':seconds s',
        slow: 'Long wait',
        queues_none: 'The queues could not be read',
        window: 'Jobs per minute since :time',
      },
      tenant: {
        title: 'Workspace health',
        description: 'This workspace only.',
        channels: 'Open live channels',
        drift: 'Your channels on the old address',
        reason_withheld: 'The details are visible to the operator.',
      },
      reason: 'What the check says',
      not_reported: 'Not reported.',
      no_figure: 'Not available',
      checked: 'Checked :time',
      never: 'Not checked',
      refresh: 'Check now',
      refreshing: 'Checking',
      polling: 'Checks again every :seconds seconds.',
      loading: 'Checking the services.',
      stale: { title: 'Could not refresh', body: 'From :time. Retrying every :seconds seconds.', nodata: 'Nothing read yet.', retry: 'Try again' },
    },
  },
}

const CHECKED = '2026-10-22T10:00:05Z'

function reverb(over: Partial<ReverbCard> = {}): ReverbCard {
  return { key: 'reverb', state: 'up', reason: 'Reverb answered.', checkedAt: CHECKED, address: 'reverb:8080', connections: 4, channels: 2, ...over }
}

function gateway(over: Partial<GatewayCard> = {}): GatewayCard {
  return {
    key: 'gateway',
    state: 'up',
    reason: 'The gateway answered.',
    checkedAt: CHECKED,
    url: 'http://gateway:8080/healthz',
    specs: [{ platform: 'telegram', status: 'matches' }],
    drift: { total: 0, platforms: [] },
    ...over,
  }
}

function queues(over: Partial<QueuesCard> = {}): QueuesCard {
  return {
    key: 'queues',
    state: 'up',
    reason: 'Horizon is running (2 instances).',
    checkedAt: CHECKED,
    failed: 3,
    failedWindowHours: 168,
    supervisors: 2,
    windowStartedAt: '2026-10-22T09:55:00Z',
    queues: [
      { name: 'flow.execution', waitSeconds: 12, waitThreshold: 60, slow: false, jobsPerMinute: 4.5 },
      { name: 'default', waitSeconds: 90, waitThreshold: 60, slow: true, jobsPerMinute: 0 },
      { name: 'messaging.system', waitSeconds: null, waitThreshold: 60, slow: false, jobsPerMinute: null },
    ],
    ...over,
  }
}

function report(over: Partial<HealthReportData> = {}): HealthReportData {
  return { reverb: reverb(), gateway: gateway(), queues: queues(), ...over }
}

function render(props: Record<string, unknown> = {}) {
  return mount(Index, { props: { pollSeconds: 15, scope: 'installation', ...props } })
}

beforeEach(() => {
  vi.useFakeTimers()
  page.props = { translations, locale: 'en' }
  reload.mockReset()
  cancelAll.mockReset()
})

afterEach(() => {
  vi.useRealTimers()
})

describe('loading', () => {
  it('shows neither green nor red before the first answer', () => {
    const wrapper = render()

    for (const key of ['reverb', 'gateway', 'queues']) {
      const card = wrapper.find(`[data-test="card-${key}"]`)

      expect(card.attributes('data-state')).toBe('loading')
      expect(card.attributes('aria-busy')).toBe('true')
      expect(card.find('[data-test="state"]').text()).toBe('Checking')
      expect(card.find('[data-test="loading"]').exists()).toBe(true)
    }
  })

  it('asks for the report itself, by a partial reload', () => {
    render()

    expect(reload).toHaveBeenCalledTimes(1)
    expect(reload.mock.calls[0]![0]).toMatchObject({ only: ['report'] })
  })
})

describe('states', () => {
  it('draws up with its figures', () => {
    const wrapper = render({ report: report() })
    const card = wrapper.find('[data-test="card-reverb"]')

    expect(card.find('[data-test="state"]').text()).toBe('Up')
    expect(card.text()).toContain('Working')
    expect(card.text()).toContain('4')
    expect(card.text()).toContain('reverb:8080')
    expect(card.find('[data-test="checked"]').text()).toMatch(/^Checked /)
    expect(wrapper.find('[data-test="drift-zero"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="spec-telegram"]').text()).toBe('telegram: In sync')
  })

  it('says down plainly, with the reason', () => {
    const wrapper = render({ report: report({ reverb: reverb({ state: 'down', reason: 'Reverb answered 503.' }) }) })
    const card = wrapper.find('[data-test="card-reverb"]')

    expect(card.find('[data-test="state"]').text()).toBe('Down')
    expect(card.find('[data-test="hint"]').text()).toBe('It said it is not healthy.')
    expect(card.find('[data-test="reason"]').text()).toContain('Reverb answered 503.')
  })

  it('keeps unknown apart from down and shows missing figures as not available', () => {
    const wrapper = render({ report: report({ reverb: reverb({ state: 'unknown', reason: 'Could not be reached.', connections: null, channels: null }) }) })
    const card = wrapper.find('[data-test="card-reverb"]')

    expect(card.find('[data-test="state"]').text()).toBe('Unknown')
    expect(card.find('[data-test="reason"]').text()).toContain('Could not be reached.')
    expect(card.findAll('[data-test="missing"]')).toHaveLength(2)
    expect(card.text()).not.toContain('Working')
  })

  it('shows a service switched off on purpose as a state of its own', () => {
    const wrapper = render({ report: report({ reverb: reverb({ state: 'disabled', reason: 'Off.', connections: null, channels: null, address: null }) }) })
    const card = wrapper.find('[data-test="card-reverb"]')

    expect(card.find('[data-test="state"]').text()).toBe('Switched off')
    expect(card.find('[data-test="disabled-text"]').text()).toBe('Console screens refresh by polling instead.')
    expect(card.find('[data-test="figure"]').exists()).toBe(false)
    expect(card.find('[data-test="reason"]').exists()).toBe(false)
  })

  it('lists channels still on the old address and what could not be read', () => {
    const drifted = render({ report: report({ gateway: gateway({ drift: { total: 3, platforms: [{ platform: 'telegram', count: 3 }] }, specs: [{ platform: 'viber', status: 'differs' }] }) }) })

    expect(drifted.find('[data-test="drift-total"]').text()).toBe('3')
    expect(drifted.find('[data-test="drift"]').text()).toContain('telegram: 3')
    expect(drifted.find('[data-test="spec-viber"]').text()).toBe('viber: Out of date')

    const unread = render({ report: report({ gateway: gateway({ specs: null, drift: null }) }) })

    expect(unread.find('[data-test="specs"] [data-test="missing"]').text()).toBe('Could not be read')
    expect(unread.find('[data-test="drift"] [data-test="missing"]').text()).toBe('Could not be counted')
  })
})

describe('the queue card', () => {
  it('lists each queue with its wait and jobs per minute, and the failed jobs over the window', () => {
    const card = render({ report: report() }).find('[data-test="card-queues"]')

    expect(card.find('[data-test="state"]').text()).toBe('Up')
    expect(card.find('[data-test="failed"]').text()).toContain('Failed jobs in the last 168 hours')
    expect(card.find('[data-test="failed"]').text()).toContain('3')
    expect(card.find('[data-test="queue-flow.execution"]').text()).toContain('12 s')
    expect(card.find('[data-test="queue-flow.execution"]').text()).toContain('4.5')
    expect(card.find('[data-test="window"]').text()).toMatch(/^Jobs per minute since /)
  })

  it('marks a wait at or over the threshold with a word, not colour alone', () => {
    const card = render({ report: report() }).find('[data-test="card-queues"]')

    expect(card.find('[data-test="queue-default"] [data-test="queue-slow"]').text()).toContain('Long wait')
    expect(card.find('[data-test="queue-flow.execution"] [data-test="queue-slow"]').exists()).toBe(false)
    expect(card.findAll('[data-test="queue-slow"]')).toHaveLength(1)
  })

  it('shows a missing wait or rate as not available, never as zero', () => {
    const row = render({ report: report() }).find('[data-test="queue-messaging.system"]')

    expect(row.text()).toBe('messaging.system' + 'Not available' + 'Not available')
    expect(row.findAll('[data-test="missing"]')).toHaveLength(1)
  })

  it('says down plainly and still lists the queues it could read', () => {
    const card = render({ report: report({ queues: queues({ state: 'down', reason: 'Horizon is not running.' }) }) }).find('[data-test="card-queues"]')

    expect(card.find('[data-test="state"]').text()).toBe('Down')
    expect(card.find('[data-test="reason"]').text()).toContain('Horizon is not running.')
    expect(card.find('[data-test="queue-table"]').exists()).toBe(true)
  })

  it('says the queues could not be read instead of drawing an empty table', () => {
    const card = render({ report: report({ queues: queues({ state: 'unknown', queues: null, failed: null, supervisors: null }) }) }).find('[data-test="card-queues"]')

    expect(card.find('[data-test="queues-none"]').text()).toBe('The queues could not be read')
    expect(card.find('[data-test="queue-table"]').exists()).toBe(false)
    expect(card.find('[data-test="failed"] [data-test="missing"]').exists()).toBe(true)
  })

  it('shows a card switched off with its sentence and no figures', () => {
    const card = render({ report: report({ queues: queues({ state: 'disabled', queues: null, failed: null, supervisors: null }) }) }).find('[data-test="card-queues"]')

    expect(card.find('[data-test="state"]').text()).toBe('Switched off')
    expect(card.find('[data-test="disabled-text"]').text()).toBe('Queues do not run through Horizon.')
    expect(card.find('[data-test="queue-table"]').exists()).toBe(false)
    expect(card.find('[data-test="failed"]').exists()).toBe(false)
  })

  it('spans both columns', () => {
    expect(render({ report: report() }).find('[data-test="card-queues"]').classes()).toContain('lg:col-span-2')
  })
})

describe('polling', () => {
  it('reloads the report every poll interval and stops when the page goes', async () => {
    reload.mockImplementation((options: { onFinish?: () => void }) => options.onFinish?.())
    const wrapper = render({ report: report() })
    reload.mockClear()

    vi.advanceTimersByTime(15_000)
    await flushPromises()

    expect(reload).toHaveBeenCalledTimes(1)
    expect(reload.mock.calls[0]![0]).toMatchObject({ only: ['report'] })

    wrapper.unmount()
    reload.mockClear()
    vi.advanceTimersByTime(60_000)

    expect(reload).not.toHaveBeenCalled()
  })

  it('does not stack a request on one still in flight', () => {
    render({ report: report(), pollSeconds: 3 })
    vi.advanceTimersByTime(9_000)

    expect(reload).toHaveBeenCalledTimes(1)
  })
})

describe('a failed request', () => {
  function failNextRequest(): void {
    reload.mockImplementation((options: { onNetworkError?: () => void; onFinish?: () => void }) => {
      options.onNetworkError?.()
      options.onFinish?.()
    })
  }

  it('keeps the last answer on screen, marked out of date', async () => {
    failNextRequest()
    const wrapper = render({ report: report() })
    await flushPromises()

    expect(wrapper.find('[data-test="stale"]').text()).toContain('Could not refresh')
    expect(wrapper.find('[data-test="card-reverb"] [data-test="state"]').text()).toBe('Up')
    expect(wrapper.find('[data-test="no-data"]').exists()).toBe(false)
  })

  it('with nothing read yet shows an error with a retry, not cards', async () => {
    failNextRequest()
    const wrapper = render()
    await flushPromises()

    expect(wrapper.find('[data-test="no-data"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="card-reverb"]').exists()).toBe(false)

    reload.mockReset()
    await wrapper.find('[data-test="retry"]').trigger('click')

    expect(reload).toHaveBeenCalledTimes(1)
  })

  it('clears the warning when a request succeeds again', async () => {
    failNextRequest()
    const wrapper = render({ report: report() })
    await flushPromises()
    expect(wrapper.find('[data-test="stale"]').exists()).toBe(true)

    reload.mockImplementation((options: { onSuccess?: () => void; onFinish?: () => void }) => {
      options.onSuccess?.()
      options.onFinish?.()
    })
    await wrapper.find('[data-test="refresh"]').trigger('click')

    expect(wrapper.find('[data-test="stale"]').exists()).toBe(false)
  })
})

describe('old data', () => {
  function answerOk(): void {
    reload.mockImplementation((options: { onSuccess?: () => void; onFinish?: () => void }) => {
      options.onSuccess?.()
      options.onFinish?.()
    })
  }

  it('is not presented as current when the answer came from history', async () => {
    reload.mockImplementation(() => undefined)
    const wrapper = render({ report: report() })
    await flushPromises()

    expect(wrapper.find('[data-test="stale"]').exists()).toBe(true)
  })

  it('is current right after an answer and old after two intervals without one', async () => {
    answerOk()
    const wrapper = render({ report: report() })
    await flushPromises()
    expect(wrapper.find('[data-test="stale"]').exists()).toBe(false)

    reload.mockImplementation(() => undefined)
    vi.advanceTimersByTime(31_000)
    await flushPromises()

    expect(wrapper.find('[data-test="stale"]').exists()).toBe(true)
  })

  it('gives up on a request that never finishes and says so', async () => {
    reload.mockImplementation(() => undefined)
    const wrapper = render({ report: report() })

    vi.advanceTimersByTime(10_000)
    await flushPromises()

    expect(cancelAll).toHaveBeenCalled()
    expect(wrapper.find('[data-test="stale"]').exists()).toBe(true)

    reload.mockClear()
    vi.advanceTimersByTime(15_000)

    expect(reload).toHaveBeenCalled()
  })

  it('says the service was not reported when the report has no word on it', () => {
    const wrapper = render({ report: report({ gateway: gateway({ state: 'unknown', reason: null, checkedAt: null, specs: null, drift: null, url: null }) }) })

    expect(wrapper.find('[data-test="card-gateway"] [data-test="reason"]').text()).toContain('Not reported.')
  })
})

describe('the tenant slice', () => {
  function slice(over: Partial<HealthReportData> = {}): HealthReportData {
    return {
      reverb: reverb({ reason: null, address: null, connections: null, channels: 3 }),
      gateway: gateway({ reason: null, url: null, specs: null, drift: { total: 2, platforms: [{ platform: 'telegram', count: 2 }] } }),
      ...over,
    }
  }

  it('has no queues card, not even while loading', () => {
    const loading = render({ scope: 'tenant' })

    expect(loading.find('[data-test="card-queues"]').exists()).toBe(false)
    expect(loading.find('[data-test="card-reverb"]').exists()).toBe(true)

    const loaded = render({ scope: 'tenant', report: slice() })

    expect(loaded.find('[data-test="card-queues"]').exists()).toBe(false)
  })

  it('uses its own title and description', () => {
    const wrapper = render({ scope: 'tenant', report: slice() })

    expect(wrapper.find('h1').text()).toBe('Workspace health')
    expect(wrapper.text()).toContain('This workspace only.')
  })

  it('shows the tenant channels and drift but no connections, address or specifications', () => {
    const wrapper = render({ scope: 'tenant', report: slice() })
    const card = wrapper.find('[data-test="card-reverb"]')

    expect(card.text()).toContain('Open live channels')
    expect(card.text()).toContain('3')
    expect(card.text()).not.toContain('Connections')
    expect(card.text()).not.toContain('Address')
    expect(wrapper.find('[data-test="card-gateway"]').text()).not.toContain('Address')
    expect(wrapper.find('[data-test="specs"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="drift"]').text()).toContain('Your channels on the old address')
    expect(wrapper.find('[data-test="drift-total"]').text()).toBe('2')
  })

  it('ignores an address or specifications even if the server sent them', () => {
    const wrapper = render({ scope: 'tenant', report: slice({ reverb: reverb({ reason: null, channels: 1 }), gateway: gateway({ reason: null }) }) })

    expect(wrapper.text()).not.toContain('reverb:8080')
    expect(wrapper.text()).not.toContain('gateway:8080')
    expect(wrapper.find('[data-test="specs"]').exists()).toBe(false)
  })

  it('says the details are withheld for a service that is down or unknown, and nothing for one that is up', () => {
    const wrapper = render({
      scope: 'tenant',
      report: slice({ reverb: reverb({ state: 'unknown', reason: null, address: null, connections: null, channels: null }), gateway: gateway({ state: 'down', reason: null, url: null, specs: null }) }),
    })

    expect(wrapper.find('[data-test="card-reverb"] [data-test="reason"]').text()).toContain('The details are visible to the operator.')
    expect(wrapper.find('[data-test="card-reverb"]').text()).not.toContain('Not reported.')
    expect(wrapper.find('[data-test="card-gateway"] [data-test="reason"]').text()).toContain('The details are visible to the operator.')

    const up = render({ scope: 'tenant', report: slice() })

    expect(up.find('[data-test="reason"]').exists()).toBe(false)
  })

  it('draws no queues card even if a queues answer arrives', () => {
    const wrapper = render({ scope: 'tenant', report: slice({ queues: queues({ checkedAt: '2030-01-01T00:00:00Z' }) }) })

    expect(wrapper.find('[data-test="card-queues"]').exists()).toBe(false)
  })
})
