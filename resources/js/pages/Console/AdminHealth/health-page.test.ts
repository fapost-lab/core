// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import Index from './Index.vue'
import type { GatewayCard, HealthReportData, ReverbCard } from './types'

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

function report(over: Partial<HealthReportData> = {}): HealthReportData {
  return { reverb: reverb(), gateway: gateway(), ...over }
}

function render(props: Record<string, unknown> = {}) {
  return mount(Index, { props: { pollSeconds: 15, ...props } })
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

    for (const key of ['reverb', 'gateway']) {
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
