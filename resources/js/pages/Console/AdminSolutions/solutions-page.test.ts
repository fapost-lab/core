// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import Index from './Index.vue'
import type { MissingRow, SolutionRow } from './types'

const { page, post } = vi.hoisted(() => ({ page: { props: {} as Record<string, unknown>, url: '/admin/solutions' }, post: vi.fn() }))

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  usePage: () => page,
  router: { post },
  Head: defineComponent({ setup: () => () => null }),
}))

const translations = {
  console: {
    form: { cancel: 'Cancel' },
    solutions: {
      navigation: 'Solutions',
      title: 'Solutions',
      description: 'Installed packages.',
      empty: 'No Solutions are installed',
      empty_hint: 'Hint',
      version: 'Version :version',
      package: 'Package :package',
      state: { active: 'On', inactive: 'Off' },
      activate: 'Turn on',
      deactivate: 'Turn off',
      last_change: { activated: 'Turned on by :actor, :date', deactivated: 'Turned off by :actor, :date', unknown: 'someone' },
      registers: { title: 'What it adds', actions: 'Flow actions', none: 'Adds nothing.' },
      ui: { title: 'Console', application: 'Application: :label', pages: 'Pages', not_published: 'Not published yet.', inactive_note: 'Hidden while off.' },
      points: { context_action: 'Action', shell_element: 'Shell element', dashboard_widget: 'Dashboard widget', settings_tab: 'Settings tab', table_column: 'Table column' },
      kit: {
        title: 'Cannot be turned on',
        description: 'Built for other versions.',
        mismatch: ':runtime: built for :required, ships :provided.',
        unreadable: ':runtime: built for ":required", unreadable; ships :provided.',
        runtime: { kit: 'Interface kit', vue: 'Vue', inertia: 'Inertia' },
      },
      deactivate_dialog: { title: 'Turn off :name?', description: 'Data is kept.', confirm: 'Turn off now' },
      missing: { title: 'No longer installed', description: 'Still on, package gone.' },
    },
  },
}

function solution(over: Partial<SolutionRow> = {}): SolutionRow {
  return {
    id: 'feedback',
    name: 'Feedback',
    description: 'Collects feedback.',
    package: 'acme/fapost-feedback',
    version: '1.2.0',
    active: false,
    actions: ['feedback.run'],
    ui: null,
    lastChange: null,
    canActivate: true,
    urls: { activate: '/admin/solutions/feedback/activate', deactivate: '/admin/solutions/feedback/deactivate' },
    ...over,
  }
}

beforeEach(() => {
  page.props = { translations, locale: 'en' }
  post.mockReset()
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('solutions page', () => {
  it('shows identity, state, version and the actions a Solution registers', () => {
    const wrapper = mount(Index, { props: { solutions: [solution()], missing: [] } })
    const card = wrapper.get('[data-test="solution-feedback"]')

    expect(card.text()).toContain('Feedback')
    expect(card.text()).toContain('Collects feedback.')
    expect(card.text()).toContain('Version 1.2.0')
    expect(card.text()).toContain('Package acme/fapost-feedback')
    expect(card.get('[data-test="state"]').text()).toBe('Off')
    expect(card.get('[data-test="actions"]').text()).toContain('feedback.run')
    expect(card.find('[data-test="ui"]').exists()).toBe(false)
  })

  it('turns an inactive Solution on with one click, to the server url', async () => {
    const wrapper = mount(Index, { props: { solutions: [solution()], missing: [] } })

    await wrapper.get('[data-test="activate"]').trigger('click')

    expect(post).toHaveBeenCalledTimes(1)
    expect(post.mock.calls[0]?.[0]).toBe('/admin/solutions/feedback/activate')
  })

  it('shows a disabled button for a Solution the server says cannot be turned on, and sends nothing', async () => {
    const wrapper = mount(Index, { props: { solutions: [solution({ canActivate: false })], missing: [] } })
    const button = wrapper.get('[data-test="activate"]')

    expect(button.attributes('disabled')).toBeDefined()
    await button.trigger('click')
    expect(post).not.toHaveBeenCalled()
  })

  it('asks before turning an active Solution off and says its data is kept', async () => {
    const wrapper = mount(Index, {
      attachTo: document.body,
      props: { solutions: [solution({ active: true, lastChange: { action: 'activated', actor: 'Ann', at: '2026-10-16T10:00:00+00:00', atLabel: 'October 16, 2026 10:00 AM UTC' } })], missing: [] },
    })

    expect(wrapper.get('[data-test="state"]').text()).toBe('On')
    expect(wrapper.get('[data-test="last-change"]').text()).toBe('Turned on by Ann, October 16, 2026 10:00 AM UTC')

    await wrapper.get('[data-test="deactivate"]').trigger('click')
    await flushPromises()

    expect(post).not.toHaveBeenCalled()
    expect(document.body.textContent).toContain('Turn off Feedback?')
    expect(document.body.textContent).toContain('Data is kept.')

    const confirm = [...document.body.querySelectorAll('button')].find((button) => button.textContent?.trim() === 'Turn off now')
    confirm?.click()
    await flushPromises()

    expect(post).toHaveBeenCalledTimes(1)
    expect(post.mock.calls[0]?.[0]).toBe('/admin/solutions/feedback/deactivate')
  })

  it('names the console points it registers and the application', () => {
    const wrapper = mount(Index, {
      props: {
        solutions: [
          solution({
            id: 'showcase',
            name: 'Showcase',
            active: true,
            actions: [],
            ui: {
              state: 'published',
              application: 'Showcase desk',
              registrations: [
                { point: 'context_action', target: 'contact.view', key: 'note', label: 'Add note' },
                { point: 'dashboard_widget', target: 'admin', key: 'stats', label: null },
              ],
              pages: ['Board/Index'],
              mismatches: [],
            },
          }),
        ],
        missing: [],
      },
    })
    const ui = wrapper.get('[data-test="ui"]').text()

    expect(ui).toContain('Application: Showcase desk')
    expect(ui).toContain('Action: Add note')
    expect(ui).toContain('Dashboard widget: stats')
    expect(ui).toContain('Board/Index')
    expect(wrapper.find('[data-test="kit-mismatch"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="registers-none"]').exists()).toBe(false)
  })

  it('says why a Solution cannot be turned on when the kit range does not match', () => {
    const wrapper = mount(Index, {
      props: {
        solutions: [
          solution({
            canActivate: false,
            ui: {
              state: 'published',
              application: null,
              registrations: [],
              pages: [],
              mismatches: [
                { component: 'kit', required: '^2.0', provided: '0.1.0', unreadable: false },
                { component: 'inertia', required: 'latest', provided: '3.7.1', unreadable: true },
              ],
            },
          }),
        ],
        missing: [],
      },
    })
    const box = wrapper.get('[data-test="kit-mismatch"]')

    expect(box.text()).toContain('Cannot be turned on')
    expect(box.get('[data-test="mismatch-kit"]').text()).toBe('Interface kit: built for ^2.0, ships 0.1.0.')
    expect(box.get('[data-test="mismatch-inertia"]').text()).toBe('Inertia: built for "latest", unreadable; ships 3.7.1.')
    // The reason sits next to a button that cannot be used.
    expect(wrapper.get('[data-test="activate"]').attributes('disabled')).toBeDefined()
  })

  it('says a console interface is not published yet', () => {
    const wrapper = mount(Index, {
      props: { solutions: [solution({ ui: { state: 'not_published', application: null, registrations: [], pages: [], mismatches: [] } })], missing: [] },
    })

    expect(wrapper.get('[data-test="not-published"]').text()).toBe('Not published yet.')
  })

  it('says a Solution adds nothing when it registers nothing', () => {
    const wrapper = mount(Index, { props: { solutions: [solution({ actions: [] })], missing: [] } })

    expect(wrapper.get('[data-test="registers-none"]').text()).toBe('Adds nothing.')
  })

  it('lists a Solution whose package is gone apart, and turns it off without asking', async () => {
    const missing: MissingRow[] = [{ id: 'ghost', lastChange: null, urls: { deactivate: '/admin/solutions/ghost/deactivate' } }]
    const wrapper = mount(Index, { props: { solutions: [], missing } })

    expect(wrapper.get('[data-test="missing-ghost"]').text()).toContain('ghost')
    expect(wrapper.text()).not.toContain('No Solutions are installed')

    await wrapper.get('[data-test="missing-ghost"] [data-test="deactivate"]').trigger('click')

    expect(post.mock.calls[0]?.[0]).toBe('/admin/solutions/ghost/deactivate')
  })

  it('shows an empty state when nothing is installed', () => {
    const wrapper = mount(Index, { props: { solutions: [], missing: [] } })

    expect(wrapper.text()).toContain('No Solutions are installed')
  })
})
