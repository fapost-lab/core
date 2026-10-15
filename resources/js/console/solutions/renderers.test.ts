// @vitest-environment happy-dom
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, type Component } from 'vue'
import { ValueCell } from '@fapost/ui/components/data-table'
import { loadSolutionComponent } from './loader'
import SolutionActions from './SolutionActions.vue'
import SolutionMount from './SolutionMount.vue'
import SolutionShellSlot from './SolutionShellSlot.vue'
import SolutionWidgets from './SolutionWidgets.vue'

/**
 * The renderers mount the fixture Solution's prebuilt modules (tests/Fixtures/Solutions/Showcase/package/dist): the
 * loader is replaced by one that imports them straight from disk, by the fixture's own `fapost-ui.json`, so the
 * modules' `vue` and `@fapost/ui/...` imports are this test's — one Vue, as Core's import map gives in the browser.
 */
const { page } = vi.hoisted(() => ({
  page: { props: {} as Record<string, unknown>, url: '/' },
}))

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  usePage: () => page,
  Link: defineComponent({
    props: { href: { type: String, required: true } },
    setup: (props, { slots, attrs }) => () => h('a', { ...attrs, href: props.href }, slots.default?.()),
  }),
}))

vi.mock('./loader', () => ({ loadSolutionComponent: vi.fn() }))

const here = dirname(fileURLToPath(import.meta.url))
const dist = '../../../../tests/Fixtures/Solutions/Showcase/package/dist'
const manifest = JSON.parse(readFileSync(resolve(here, dist, 'fapost-ui.json'), 'utf8')) as { components: Record<string, string> }
const modules = import.meta.glob<{ default: Component }>('../../../../tests/Fixtures/Solutions/Showcase/package/dist/assets/*.js')

/** A component that throws while rendering, as a broken Solution would. */
const Throwing = defineComponent({
  setup: () => () => {
    throw new Error('the widget broke')
  },
})

function emptyExtensions() {
  return {
    shell: { floating: [], header: [] },
    actions: { 'contact.view': [], 'contact.row': [], 'conversation.view': [] },
    widgets: { console: [], admin: [] },
    tabs: { assistant: [], tenant: [] },
    columns: { contacts: [], conversations: [] },
  }
}

function base(id: string, order = 0) {
  const [solution, key] = id.split('.')

  return { id, solution, key, order }
}

let errors: ReturnType<typeof vi.spyOn>

beforeEach(() => {
  page.props = {
    extensions: emptyExtensions(),
    assistants: { current: { id: 'a1', name: 'Bot' }, items: [], back: { href: '/', external: false } },
    translations: { console: { extensions: { row_actions: 'More actions: :name' } } },
    locale: 'en',
  }

  vi.mocked(loadSolutionComponent).mockImplementation(async (name: string) => {
    if (name === 'showcase::Broken/Throws') {
      return Throwing
    }

    const path = name.startsWith('showcase::') ? manifest.components[name.slice('showcase::'.length)] : undefined
    const load = path === undefined ? undefined : modules[`${dist}/${path}`]

    if (load === undefined) {
      throw new Error(`${name}: not in the map of active Solutions`)
    }

    return (await load()).default
  })

  errors = vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
  errors.mockRestore()
  document.body.innerHTML = ''
})

/** A Core screen with a Solution element in it: the screen must survive whatever the element does. */
function screenWith(component: string) {
  return mount(
    defineComponent({
      setup: () => () => h('section', [h('h1', 'Core screen'), h(SolutionMount, { component, recordId: 'c1', assistantId: 'a1' })]),
    }),
  )
}

describe('mounting a Solution component', () => {
  it('mounts the prebuilt module with the record and the assistant', async () => {
    const wrapper = screenWith('showcase::Actions/Note')
    // The first import of a module takes more than a tick.
    await vi.waitFor(() => expect(wrapper.find('[data-showcase="note"]').exists()).toBe(true))

    const note = wrapper.find('[data-showcase="note"]')
    // The kit's badge, imported by the module through `@fapost/ui/components/badge`.
    expect(note.find('[data-slot="badge"]').text()).toBe('Note')
    expect(note.find('.showcase-mark').text()).toBe('c1')
    expect(vi.mocked(loadSolutionComponent)).toHaveBeenCalledWith('showcase::Actions/Note')
  })

  it('hides a component that does not load and keeps the screen', async () => {
    const wrapper = screenWith('showcase::Missing/Thing')
    await flushPromises()

    expect(wrapper.find('h1').text()).toBe('Core screen')
    expect(wrapper.find('[data-solution-component]').exists()).toBe(false)
    expect(errors).toHaveBeenCalledWith('[solutions] showcase::Missing/Thing is hidden:', expect.any(Error))
  })

  it('hides a component that throws while rendering and keeps the screen', async () => {
    const wrapper = screenWith('showcase::Broken/Throws')
    await flushPromises()

    expect(wrapper.find('h1').text()).toBe('Core screen')
    expect(wrapper.find('[data-solution-component]').exists()).toBe(false)
    expect(errors).toHaveBeenCalledWith('[solutions] showcase::Broken/Throws is hidden:', expect.any(Error))
  })

  it('passes no id it was not given', async () => {
    const wrapper = mount(SolutionMount, { props: { component: 'showcase::Widgets/Stats' } })

    await vi.waitFor(() => expect(wrapper.find('[data-showcase="stats"]').exists()).toBe(true))
    expect(wrapper.find('.showcase-mark').exists()).toBe(false)
  })
})

describe('context actions', () => {
  it('draws nothing when no Solution declared one', () => {
    const wrapper = mount(SolutionActions, { props: { target: 'contact.view', recordId: 'c1' } })

    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('draws a link as a link to the Solution page, with its lucide icon', async () => {
    page.props.extensions = {
      ...emptyExtensions(),
      actions: {
        ...emptyExtensions().actions,
        'contact.view': [{ ...base('showcase.open'), kind: 'link', label: 'Open in Showcase', icon: 'external-link', href: '/assistant/a1/apps/showcase/contacts/c1' }],
      },
    }

    const wrapper = mount(SolutionActions, { props: { target: 'contact.view', recordId: 'c1' } })
    const anchor = wrapper.find('a[data-solution-action="showcase.open"]')

    expect(anchor.attributes('href')).toBe('/assistant/a1/apps/showcase/contacts/c1')
    expect(anchor.text()).toBe('Open in Showcase')
    // `external-link` is not one of Core's icons: it comes from the lazy lucide catalogue.
    await vi.waitFor(() => expect(wrapper.find('a[data-solution-action="showcase.open"] svg').classes()).toContain('lucide-external-link'))
  })

  it('opens a dialog with the Solution component for the record', async () => {
    page.props.extensions = {
      ...emptyExtensions(),
      actions: {
        ...emptyExtensions().actions,
        'conversation.view': [{ ...base('showcase.note'), kind: 'dialog', label: 'Note', icon: 'sticky-note', component: 'showcase::Actions/Note' }],
      },
    }

    const wrapper = mount(SolutionActions, { props: { target: 'conversation.view', recordId: 'v1' }, attachTo: document.body })

    expect(document.querySelector('[data-showcase="note"]')).toBeNull()

    await wrapper.find('button[data-solution-action="showcase.note"]').trigger('click')
    await vi.waitFor(() => expect(document.querySelector('[data-showcase="note"]')).not.toBeNull())

    const note = document.querySelector('[data-showcase="note"]')
    expect(note).not.toBeNull()
    expect(note?.querySelector('.showcase-mark')?.textContent).toBe('v1')
    expect(document.querySelector('[data-slot="dialog-content"]')?.textContent).toContain('Note')

    wrapper.unmount()
  })

  it('puts the row actions behind one menu button', () => {
    page.props.extensions = {
      ...emptyExtensions(),
      actions: {
        ...emptyExtensions().actions,
        'contact.row': [{ ...base('showcase.open'), kind: 'link', label: 'Open in Showcase', icon: null, hrefTemplate: '/assistant/a1/apps/showcase/contacts/__record__' }],
      },
    }

    const wrapper = mount(SolutionActions, { props: { target: 'contact.row', recordId: 'c1', variant: 'row', name: 'Ann' } })
    const trigger = wrapper.find('button[data-solution-actions="row"]')

    expect(trigger.exists()).toBe(true)
    expect(trigger.attributes('aria-label')).toBe('More actions: Ann')
  })
})

describe('the shell slot', () => {
  it('mounts its elements only after the page has rendered', async () => {
    page.props.extensions = {
      ...emptyExtensions(),
      shell: { floating: [{ ...base('showcase.helper'), component: 'showcase::Shell/Helper' }], header: [] },
    }

    const wrapper = mount(SolutionShellSlot, { props: { place: 'floating' } })

    expect(wrapper.find('[data-solution-slot]').exists()).toBe(false)
    expect(vi.mocked(loadSolutionComponent)).not.toHaveBeenCalled()

    await vi.waitFor(() => expect(wrapper.find('[data-solution-slot="floating"]').exists()).toBe(true))
    await flushPromises()

    expect(wrapper.find('[data-solution-slot="floating"]').classes()).toContain('fixed')
    expect(vi.mocked(loadSolutionComponent)).toHaveBeenCalledWith('showcase::Shell/Helper')
  })

  it('draws nothing in a slot nobody fills', async () => {
    const wrapper = mount(SolutionShellSlot, { props: { place: 'header' } })
    await new Promise((done) => setTimeout(done, 20))

    expect(wrapper.html()).not.toContain('data-solution-slot')
  })
})

describe('dashboard widgets', () => {
  it('spans each widget by its size', async () => {
    page.props.extensions = {
      ...emptyExtensions(),
      widgets: {
        console: [
          { ...base('showcase.stats'), component: 'showcase::Widgets/Stats', size: 'double' },
          { ...base('showcase.small', 1), component: 'showcase::Widgets/Stats', size: 'single' },
        ],
        admin: [],
      },
    }

    const wrapper = mount(SolutionWidgets, { props: { target: 'console', columns: 2 } })
    await vi.waitFor(() => expect(wrapper.findAll('[data-showcase="stats"]')).toHaveLength(2))

    const cells = wrapper.findAll('[data-widget-size]')
    expect(cells.map((cell) => cell.attributes('data-widget-size'))).toEqual(['double', 'single'])
    expect(cells[0].classes()).toContain('md:col-span-2')
    expect(cells[1].classes()).not.toContain('md:col-span-2')
    expect(wrapper.findAll('[data-showcase="stats"]')).toHaveLength(2)
  })

  it('draws no grid without widgets', () => {
    const wrapper = mount(SolutionWidgets, { props: { target: 'admin', columns: 3 } })

    expect(wrapper.find('[data-solution-widgets]').exists()).toBe(false)
  })

  it('drops a failing widget from the grid', async () => {
    page.props.extensions = {
      ...emptyExtensions(),
      widgets: { console: [], admin: [{ ...base('showcase.overview'), component: 'showcase::Broken/Throws', size: 'full' }] },
    }

    const wrapper = mount(SolutionWidgets, { props: { target: 'admin', columns: 3 } })
    await flushPromises()

    expect(wrapper.find('[data-widget-size]').exists()).toBe(false)
  })
})

describe('a Solution column cell', () => {
  it('draws each cell type', () => {
    const cell = (type: string, value: string | number | null, tones = {}) => mount(ValueCell, { props: { type, value, locale: 'en', tones } })

    expect(cell('number', 1234).find('[data-cell="number"]').text()).toBe('1,234')
    expect(cell('text', 'Kyiv').find('[data-cell="text"]').text()).toBe('Kyiv')
    expect(cell('text', null).find('[data-cell="empty"]').text()).toBe('—')
    expect(cell('badge', 'vip', { vip: 'info' }).find('[data-slot="badge"]').classes()).toContain('bg-info')
    expect(cell('status', 'happy', { happy: 'success' }).find('[data-slot="status-dot"] .bg-success-dot').exists()).toBe(true)
    expect(cell('date', '2020-01-01T00:00:00Z').find('[data-cell="text"]').attributes('title')).toBe('2020-01-01T00:00:00Z')
  })
})
