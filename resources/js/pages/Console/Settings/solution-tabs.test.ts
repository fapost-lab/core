// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import { router } from '@inertiajs/vue3'
import type { TabExtension } from '../../../console/solutions/extensions'
import { loadSolutionComponent } from '../../../console/solutions/loader'
import { extensionsWith, translationPaths } from '../../../console/solutions/screen-test-support'
import Edit from './Edit.vue'

/**
 * The assistant's settings with a Solution's tab: it follows Core's tabs, `?tab=` opens it in place of Core's form, and
 * a server error on Core's form brings the operator back to the Core tab that holds it.
 */
const { page } = vi.hoisted(() => ({ page: { props: {} as Record<string, unknown>, url: '/assistant/a1/settings' } }))

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  usePage: () => page,
  Head: defineComponent({ setup: () => () => null }),
}))

vi.mock('../../../console/solutions/loader', () => ({ loadSolutionComponent: vi.fn() }))

const assistantTab: TabExtension = { id: 'showcase.general', solution: 'showcase', key: 'general', order: 0, label: 'Showcase', component: 'showcase::Settings/Assistant' }

/** The screen's own tabs: the first tab list (the message fields hold tab lists of their own, by language). */
function screenTabs(wrapper: ReturnType<typeof mountScreen>) {
  return wrapper.find('[role="tablist"]').findAll('[role="tab"]')
}

function mountScreen() {
  return mount(Edit, {
    props: {
      settings: {
        defaultLanguage: 'en',
        availableCountries: [],
        defaultFlowId: null,
        defaultFlowMissing: false,
        fallbackMessage: [{ locale: 'en', text: '' }],
        busyMessage: [{ locale: 'en', text: '' }],
        commands: [],
        settings: [],
      },
      languageLocked: false,
      languages: ['en'],
      options: { languages: [{ value: 'en', label: 'English' }], countries: [], flows: [], commandTypes: [] },
      limit: { reached: false, hint: null },
      can: { createFlow: false },
      urls: { submit: '/assistant/a1/settings', storeFlow: '/assistant/a1/settings/flow' },
    } as never,
  })
}

beforeEach(() => {
  page.url = '/assistant/a1/settings'
  page.props = {
    translations: { console: translationPaths('console') },
    locale: 'en',
    assistants: { current: { id: 'a1', name: 'Bot' } },
    extensions: extensionsWith({ tabs: { assistant: [assistantTab] } }),
  }
  vi.mocked(loadSolutionComponent).mockResolvedValue(defineComponent({ setup: () => () => h('div', { 'data-showcase': 'assistant' }, 'Showcase settings') }))
  vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('Solution tabs in the assistant settings', () => {
  it('leaves Core’s three tabs alone without a Solution tab', () => {
    page.props.extensions = extensionsWith()
    const wrapper = mountScreen()

    expect(screenTabs(wrapper)).toHaveLength(3)
    expect(wrapper.find('[data-solution-tab]').exists()).toBe(false)
  })

  it('opens the Solution tab from ?tab= with the assistant, in place of Core’s form', async () => {
    page.url = '/assistant/a1/settings?tab=showcase.general'
    const wrapper = mountScreen()

    await vi.waitFor(() => expect(wrapper.find('[data-showcase="assistant"]').exists()).toBe(true))
    expect(screenTabs(wrapper)).toHaveLength(4)
    expect(wrapper.find('form').attributes('style')).toContain('display: none')
  })

  it('sends Core’s form with put, and brings a server error back from a Solution tab', async () => {
    let visit: { onError?: (errors: Record<string, string>) => void } = {}
    const put = vi.spyOn(router, 'put').mockImplementation((_url, _data, options) => {
      visit = options ?? {}
    })
    const wrapper = mountScreen()

    await wrapper.find('form').trigger('submit')
    expect(put).toHaveBeenCalledWith('/assistant/a1/settings', expect.objectContaining({ default_language: 'en' }), expect.anything())

    // The operator moves to the Solution's tab before the answer comes.
    await wrapper.find('[data-solution-tab="showcase.general"]').trigger('mousedown', { button: 0 })
    expect(wrapper.find('form').attributes('style')).toContain('display: none')

    visit.onError?.({ default_language: 'Pick a language.' })
    await vi.waitFor(() => expect(wrapper.text()).toContain('Pick a language.'))

    expect(screenTabs(wrapper).find((tab) => tab.attributes('data-state') === 'active')?.text()).toContain('console.settings.tabs.general')
    expect(wrapper.find('form').attributes('style') ?? '').not.toContain('display: none')
  })
})
