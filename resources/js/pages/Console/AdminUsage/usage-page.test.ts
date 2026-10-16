// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import UsageWidget from '../AdminDashboard/UsageWidget.vue'
import type { UsageWidgetData } from '../AdminDashboard/types'
import Index from './Index.vue'
import UsageBar from './UsageBar.vue'
import type { UsageRowData } from './types'

const { page } = vi.hoisted(() => ({ page: { props: {} as Record<string, unknown>, url: '/admin/usage' } }))

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  usePage: () => page,
  Head: defineComponent({ setup: () => () => null }),
}))

const translations = {
  console: {
    usage: {
      navigation: 'Usage and limits',
      title: 'Usage and limits',
      description: 'How much is used.',
      no_limits: 'No limits are set on this installation.',
      sections: {
        records: { title: 'Records', description: 'Held at a time.' },
        per_period: { title: 'Per period', description: 'Resets.', until: 'Period until :date' },
        bytes: { title: 'Storage', description: 'Files.' },
      },
      of: ':used of :limit',
      only: 'Limit: :limit',
      meter: ':name, :percent% used',
      state: { unlimited: 'Unlimited', not_reported: 'No data', unavailable: 'Unavailable', near: 'Almost used up', reached: 'Limit reached' },
      empty: 'There are no limits to show.',
      empty_hint: 'Hint',
    },
    admin_dashboard: { usage: { title: 'Limits and usage', description: 'Closest to the limit.', all: 'All limits', none: 'No limits are set on this installation.', more: 'Showing :shown of :total limits.', unavailable: 'Some figures are unavailable.' } },
  },
}

function row(over: Partial<UsageRowData>): UsageRowData {
  return {
    key: 'assistants',
    label: 'Assistants',
    kind: 'records',
    state: 'ok',
    used: 3,
    limit: 5,
    usedText: '3',
    limitText: '5',
    percent: 60,
    periodEndsAt: null,
    periodEndsLabel: null,
    ...over,
  }
}

beforeEach(() => {
  page.props = { translations, locale: 'en' }
})

describe('UsageBar', () => {
  it('is a meter with its value and range for assistive technology', () => {
    const bar = mount(UsageBar, { props: { percent: 82.4, tone: 'warning', label: 'Assistants, 82% used' } })
    const meter = bar.get('[role="meter"]')

    expect(meter.attributes('aria-valuenow')).toBe('82')
    expect(meter.attributes('aria-valuemin')).toBe('0')
    expect(meter.attributes('aria-valuemax')).toBe('100')
    expect(meter.attributes('aria-label')).toBe('Assistants, 82% used')
    expect(bar.get('[role="meter"] > div').classes()).toContain('bg-warning-dot')
  })

  it('never goes past the ends of the range', () => {
    expect(mount(UsageBar, { props: { percent: 140, tone: 'danger', label: 'x' } }).get('[role="meter"]').attributes('aria-valuenow')).toBe('100')
    expect(mount(UsageBar, { props: { percent: -3, tone: 'primary', label: 'x' } }).get('[role="meter"]').attributes('aria-valuenow')).toBe('0')
  })
})

describe('usage page', () => {
  it('lists each limit in its card with "N of M", a bar and the state badge', () => {
    const wrapper = mount(Index, {
      props: {
        rows: [
          row({}),
          row({ key: 'flows', label: 'Flows', state: 'near', used: 4, usedText: '4', percent: 80 }),
          row({ key: 'calls', label: 'Calls', kind: 'per_period', state: 'reached', used: 9, usedText: '9', limit: 0, limitText: '0', percent: 100, periodEndsLabel: 'November 1, 2026' }),
          row({ key: 'media_storage', label: 'Media storage', kind: 'bytes', state: 'unlimited', limit: null, limitText: null, percent: null, usedText: '1.5 MB' }),
        ],
        action: { label: 'Upgrade plan', url: 'https://example.test/billing' },
        limited: true,
      },
    })

    expect(wrapper.get('[data-test="row-assistants"] [data-test="value"]').text()).toBe('3 of 5')
    expect(wrapper.find('[data-test="row-assistants"] [data-test="state"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="row-flows"] [data-test="state"]').text()).toBe('Almost used up')
    expect(wrapper.get('[data-test="row-flows"] [data-test="usage-bar"]').attributes('data-tone')).toBe('warning')
    expect(wrapper.get('[data-test="row-calls"] [data-test="state"]').text()).toBe('Limit reached')
    expect(wrapper.get('[data-test="section-per_period"]').text()).toContain('Period until November 1, 2026')
    expect(wrapper.get('[data-test="row-media_storage"] [data-test="state"]').text()).toBe('Unlimited')
    expect(wrapper.find('[data-test="row-media_storage"] [data-test="usage-bar"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="operator-action"]').attributes('href')).toBe('https://example.test/billing')
    expect(wrapper.find('[data-test="no-limits"]').exists()).toBe(false)
  })

  it('says no limits are set on a self-hosted installation and has no operator button', () => {
    const wrapper = mount(Index, {
      props: { rows: [row({ state: 'unlimited', limit: null, limitText: null, percent: null })], action: null, limited: false },
    })

    expect(wrapper.get('[data-test="no-limits"]').text()).toBe('No limits are set on this installation.')
    expect(wrapper.find('[data-test="operator-action"]').exists()).toBe(false)
  })

  it('shows unknown values as a dash and as "no data", not as unlimited', () => {
    const wrapper = mount(Index, {
      props: {
        rows: [
          row({ key: 'calls', kind: 'per_period', state: 'unavailable', used: null, usedText: null, percent: null }),
          row({ key: 'msgs', kind: 'per_period', state: 'not_reported', used: null, usedText: null, percent: null }),
        ],
        action: null,
        limited: true,
      },
    })

    expect(wrapper.get('[data-test="row-calls"] [data-test="value"]').text()).toBe('—')
    expect(wrapper.get('[data-test="row-calls"] [data-test="state"]').text()).toBe('Unavailable')
    expect(wrapper.get('[data-test="row-msgs"] [data-test="value"]').text()).toBe('Limit: 5')
    expect(wrapper.get('[data-test="row-msgs"] [data-test="state"]').text()).toBe('No data')
  })

  it('shows an empty state when there is nothing to list', () => {
    const wrapper = mount(Index, { props: { rows: [], action: null, limited: false } })

    expect(wrapper.text()).toContain('There are no limits to show.')
  })
})

describe('dashboard usage widget', () => {
  const widget = (over: Partial<UsageWidgetData>): UsageWidgetData => ({ rows: [], total: 0, unavailable: 0, action: null, limited: true, url: '/admin/usage', ...over })

  it('lists the rows and links to the full page', () => {
    const wrapper = mount(UsageWidget, {
      props: { usage: widget({ rows: [row({ key: 'flows', label: 'Flows', state: 'near', used: 4, usedText: '4', percent: 80 })], total: 3 }) },
    })

    expect(wrapper.get('[data-test="usage-flows"]').text()).toContain('4 of 5')
    expect(wrapper.get('[data-test="usage-flows"]').text()).toContain('Almost used up')
    expect(wrapper.text()).toContain('Showing 1 of 3 limits.')
    expect(wrapper.get('[data-test="usage-all"]').attributes('href')).toBe('/admin/usage')
  })

  it('says figures are unavailable instead of an empty list', () => {
    const wrapper = mount(UsageWidget, { props: { usage: widget({ unavailable: 3 }) } })

    expect(wrapper.find('[data-test="usage-unavailable"]').exists()).toBe(true)
    expect(wrapper.find('li').exists()).toBe(false)
  })

  it('says in one line that no limits are set', () => {
    const wrapper = mount(UsageWidget, { props: { usage: widget({ limited: false }) } })

    expect(wrapper.text()).toContain('No limits are set on this installation.')
    expect(wrapper.find('li').exists()).toBe(false)
  })
})
