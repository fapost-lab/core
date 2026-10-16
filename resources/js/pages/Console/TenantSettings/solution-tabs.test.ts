// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { router } from '@inertiajs/vue3'
import type { TabExtension } from '../../../console/solutions/extensions'
import { loadSolutionComponent } from '../../../console/solutions/loader'
import { extensionsWith, translationPaths } from '../../../console/solutions/screen-test-support'
import Edit from './Edit.vue'

/**
 * The tenant settings screen with and without a Solution's tab: without one the screen is Core's alone (D12); with
 * one, the tab follows Core's, `?tab=` opens it, and it replaces Core's form while open.
 */
const { page } = vi.hoisted(() => ({ page: { props: {} as Record<string, unknown>, url: '/admin/settings' } }))

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  usePage: () => page,
  Head: defineComponent({ setup: () => () => null }),
}))

vi.mock('../../../console/solutions/loader', () => ({ loadSolutionComponent: vi.fn() }))

const tenantTab: TabExtension = { id: 'showcase.billing', solution: 'showcase', key: 'billing', order: 0, label: 'Billing', component: 'showcase::Settings/Tenant' }

function extensions(tabs: TabExtension[]) {
  return extensionsWith({ tabs: { tenant: tabs } })
}

function mountScreen() {
  return mount(Edit, {
    props: {
      settings: {
        content_base_language: 'en',
        available_languages: ['en'],
        fallback_language: 'en',
        messaging_rate_limit: 30,
        broadcast_chunk_size: 100,
        broadcast_backpressure: true,
        flow_session_ttl: 3600,
        max_retry_attempts: 3,
        flow_fallback_message: '',
      },
      baseLanguageLocked: false,
      options: { languages: [{ value: 'en', label: 'English' }] },
      urls: { submit: '/admin/settings' },
    } as never,
  })
}

beforeEach(() => {
  page.url = '/admin/settings'
  page.props = { translations: { console: translationPaths('console') }, locale: 'en', assistants: null, extensions: extensions([]) }
  vi.mocked(loadSolutionComponent).mockResolvedValue(defineComponent({ setup: () => () => h('div', { 'data-showcase': 'tenant' }, 'Billing form') }))
  vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('Solution tabs in the tenant settings', () => {
  it('leaves the screen as Core draws it without a Solution tab', () => {
    const wrapper = mountScreen()

    expect(wrapper.findAll('[role="tab"]')).toHaveLength(4)
    expect(wrapper.find('[data-solution-tab]').exists()).toBe(false)
    expect(wrapper.find('form').attributes('style') ?? '').not.toContain('display: none')
  })

  it('adds a Solution tab after Core’s and keeps Core’s form open first', () => {
    page.props.extensions = extensions([tenantTab])
    const wrapper = mountScreen()

    const tabs = wrapper.findAll('[role="tab"]')
    expect(tabs).toHaveLength(5)
    expect(tabs[4].attributes('data-solution-tab')).toBe('showcase.billing')
    expect(tabs[4].text()).toBe('Billing')
    expect(wrapper.find('form').attributes('style') ?? '').not.toContain('display: none')
    expect(vi.mocked(loadSolutionComponent)).not.toHaveBeenCalled()
  })

  it('opens the Solution tab from ?tab= in place of Core’s form', async () => {
    page.props.extensions = extensions([tenantTab])
    page.url = '/admin/settings?tab=showcase.billing'
    const wrapper = mountScreen()
    await flushPromises()

    await vi.waitFor(() => expect(wrapper.find('[data-showcase="tenant"]').exists()).toBe(true))
    expect(vi.mocked(loadSolutionComponent)).toHaveBeenCalledWith('showcase::Settings/Tenant')
    expect(wrapper.find('form').attributes('style')).toContain('display: none')
  })

  it('opens Core’s first tab for a Solution tab the user does not see', () => {
    page.url = '/admin/settings?tab=showcase.billing'
    const wrapper = mountScreen()

    expect(wrapper.find('[role="tab"][data-state="active"]').text()).toContain('console.tenant_settings.tabs.languages')
    expect(wrapper.find('form').attributes('style') ?? '').not.toContain('display: none')
  })

  it('sends Core’s form with put, and brings a server error back from a Solution tab', async () => {
    page.props.extensions = extensions([tenantTab])
    let visit: { onError?: (errors: Record<string, string>) => void } = {}
    const put = vi.spyOn(router, 'put').mockImplementation((_url, _data, options) => {
      visit = options ?? {}
    })
    const wrapper = mountScreen()

    await wrapper.find('form').trigger('submit')
    expect(put).toHaveBeenCalledWith('/admin/settings', expect.objectContaining({ messaging_rate_limit: 30 }), expect.anything())

    // The operator moves to the Solution's tab before the answer comes.
    await wrapper.find('[data-solution-tab="showcase.billing"]').trigger('mousedown', { button: 0 })
    expect(wrapper.find('form').attributes('style')).toContain('display: none')

    visit.onError?.({ messaging_rate_limit: 'The rate limit is too high.' })
    await vi.waitFor(() => expect(wrapper.text()).toContain('The rate limit is too high.'))

    expect(wrapper.find('[role="tab"][data-state="active"]').text()).toContain('console.tenant_settings.tabs.runtime')
    expect(wrapper.find('form').attributes('style') ?? '').not.toContain('display: none')
  })
})
