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
      details: 'Details',
      last_change: { activated: 'Turned on by :actor, :date', deactivated: 'Turned off by :actor, :date', unknown: 'someone' },
      registers: { title: 'What it adds', actions: 'Flow actions', none: 'Adds nothing.' },
      ui: { title: 'Console', application: 'Application: :label', pages: 'Pages', not_published: 'Not published yet.', inactive_note: 'Hidden while off.' },
      points: { context_action: 'Action', shell_element: 'Shell element', dashboard_widget: 'Dashboard widget', settings_tab: 'Settings tab', table_column: 'Table column' },
      kit: {
        badge: 'Interface does not fit',
        title: 'Cannot be turned on',
        description: 'Built for other versions.',
        mismatch: ':runtime: built for :required, ships :provided.',
        unreadable: ':runtime: built for ":required", unreadable; ships :provided.',
        runtime: { kit: 'Interface kit', vue: 'Vue', inertia: 'Inertia' },
      },
      entitlement: {
        badge: 'Not in your plan',
        paused: 'Paused',
        not_entitled: 'Not included.',
        unavailable: 'Could not check.',
        paused_note: 'Paused, data kept.',
        off_note: 'Not in your plan, cannot be turned on.',
        unavailable_note: 'Plan could not be checked.',
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
    entitlement: { state: 'entitled', paused: false, action: null },
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

const dialog = (selector: string): Element | null => document.body.querySelector(`[data-test="details-dialog"] ${selector}`)

async function openDetails(wrapper: ReturnType<typeof mount>, id = 'feedback'): Promise<void> {
  await wrapper.get(`[data-test="solution-${id}"] [data-test="details"]`).trigger('click')
  await flushPromises()
}

function mountPage(solutions: SolutionRow[], missing: MissingRow[] = []) {
  return mount(Index, { attachTo: document.body, props: { solutions, missing } })
}

const published = (over: Partial<NonNullable<SolutionRow['ui']>> = {}): NonNullable<SolutionRow['ui']> => ({
  state: 'published',
  application: null,
  registrations: [],
  pages: [],
  mismatches: [],
  ...over,
})

describe('solutions page', () => {
  it('shows a compact card: identity, state, one-line summary and the buttons, but not the details', () => {
    const wrapper = mountPage([solution()])
    const card = wrapper.get('[data-test="solution-feedback"]')

    expect(card.text()).toContain('Feedback')
    expect(card.get('[data-test="state"]').text()).toBe('Off')
    expect(card.get('[data-test="summary"]').text()).toBe('Collects feedback.')
    expect(card.text()).toContain('Version 1.2.0')
    expect(card.text()).toContain('Package acme/fapost-feedback')
    expect(card.find('[data-test="activate"]').exists()).toBe(true)
    expect(card.find('[data-test="details"]').exists()).toBe(true)
    expect(card.find('[data-test="actions"]').exists()).toBe(false)
    expect(card.find('[data-test="last-change"]').exists()).toBe(false)
    expect(card.find('[data-test="ui"]').exists()).toBe(false)
    expect(document.body.querySelector('[data-test="details-dialog"]')).toBeNull()
  })

  it('opens the details with the full description, the journal line and the actions', async () => {
    const lastChange = { action: 'activated' as const, actor: 'Ann', at: '2026-10-16T10:00:00+00:00', atLabel: 'October 16, 2026 10:00 AM UTC' }
    const wrapper = mountPage([solution({ lastChange })])

    await openDetails(wrapper)

    expect(dialog('[role="heading"], h2')?.textContent).toContain('Feedback')
    expect(document.body.querySelector('[data-test="details-dialog"]')?.textContent).toContain('Collects feedback.')
    expect(dialog('[data-test="last-change"]')?.textContent).toBe('Turned on by Ann, October 16, 2026 10:00 AM UTC')
    expect(dialog('[data-test="actions"]')?.textContent).toContain('feedback.run')
    expect(dialog('[data-test="ui"]')).toBeNull()
  })

  it('turns an inactive Solution on with one click, to the server url', async () => {
    const wrapper = mountPage([solution()])

    await wrapper.get('[data-test="activate"]').trigger('click')

    expect(post).toHaveBeenCalledTimes(1)
    expect(post.mock.calls[0]?.[0]).toBe('/admin/solutions/feedback/activate')
  })

  it('shows a disabled button for a Solution the server says cannot be turned on, and sends nothing', async () => {
    const wrapper = mountPage([solution({ canActivate: false })])
    const button = wrapper.get('[data-test="activate"]')

    expect(button.attributes('disabled')).toBeDefined()
    await button.trigger('click')
    expect(post).not.toHaveBeenCalled()
  })

  it('asks before turning an active Solution off and says its data is kept', async () => {
    const wrapper = mountPage([solution({ active: true })])

    expect(wrapper.get('[data-test="state"]').text()).toBe('On')

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

  it('names the console points it registers and the application in the details', async () => {
    const wrapper = mountPage([
      solution({
        id: 'showcase',
        name: 'Showcase',
        active: true,
        actions: [],
        ui: published({
          application: 'Showcase desk',
          registrations: [
            { point: 'context_action', target: 'contact.view', key: 'note', label: 'Add note' },
            { point: 'dashboard_widget', target: 'admin', key: 'stats', label: null },
          ],
          pages: ['Board/Index'],
        }),
      }),
    ])

    await openDetails(wrapper, 'showcase')
    const ui = dialog('[data-test="ui"]')?.textContent ?? ''

    expect(ui).toContain('Application: Showcase desk')
    expect(ui).toContain('Action: Add note')
    expect(ui).toContain('Dashboard widget: stats')
    expect(ui).toContain('Board/Index')
    expect(dialog('[data-test="kit-mismatch"]')).toBeNull()
    expect(dialog('[data-test="registers-none"]')).toBeNull()
    expect(wrapper.find('[data-test="kit-badge"]').exists()).toBe(false)
  })

  it('flags a kit mismatch on the card and lists the reasons in the details', async () => {
    const wrapper = mountPage([
      solution({
        canActivate: false,
        ui: published({
          mismatches: [
            { component: 'kit', required: '^2.0', provided: '0.1.0', unreadable: false },
            { component: 'inertia', required: 'latest', provided: '3.7.1', unreadable: true },
          ],
        }),
      }),
    ])

    expect(wrapper.get('[data-test="kit-badge"]').text()).toBe('Interface does not fit')
    // The reason sits next to a button that cannot be used.
    expect(wrapper.get('[data-test="activate"]').attributes('disabled')).toBeDefined()

    await openDetails(wrapper)

    expect(dialog('[data-test="kit-mismatch"]')?.textContent).toContain('Cannot be turned on')
    expect(dialog('[data-test="mismatch-kit"]')?.textContent).toBe('Interface kit: built for ^2.0, ships 0.1.0.')
    expect(dialog('[data-test="mismatch-inertia"]')?.textContent).toBe('Inertia: built for "latest", unreadable; ships 3.7.1.')
  })

  it('says a console interface is not published yet', async () => {
    const wrapper = mountPage([solution({ ui: published({ state: 'not_published' }) })])

    await openDetails(wrapper)

    expect(dialog('[data-test="not-published"]')?.textContent).toBe('Not published yet.')
  })

  it('says a Solution adds nothing when it registers nothing', async () => {
    const wrapper = mountPage([solution({ actions: [] })])

    await openDetails(wrapper)

    expect(dialog('[data-test="registers-none"]')?.textContent).toBe('Adds nothing.')
  })

  it('lists a Solution whose package is gone apart, and turns it off without asking', async () => {
    const missing: MissingRow[] = [{ id: 'ghost', lastChange: null, urls: { deactivate: '/admin/solutions/ghost/deactivate' } }]
    const wrapper = mountPage([], missing)

    expect(wrapper.get('[data-test="missing-ghost"]').text()).toContain('ghost')
    expect(wrapper.text()).not.toContain('No Solutions are installed')
    expect(wrapper.find('[data-test="solutions-grid"]').exists()).toBe(false)

    await wrapper.get('[data-test="missing-ghost"] [data-test="deactivate"]').trigger('click')

    expect(post.mock.calls[0]?.[0]).toBe('/admin/solutions/ghost/deactivate')
  })

  it('badges a Solution the plan does not include, and explains it with the operator button in the details', async () => {
    const wrapper = mountPage([
      solution({ canActivate: false, entitlement: { state: 'not_entitled', paused: false, action: { label: 'See plans', url: 'https://ops.example.test/plans' } } }),
    ])

    expect(wrapper.get('[data-test="not-entitled"]').text()).toBe('Not in your plan')
    expect(wrapper.get('[data-test="activate"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="entitlement"]').exists()).toBe(false)

    await openDetails(wrapper)

    expect(dialog('[data-test="entitlement"]')?.textContent).toContain('Not in your plan, cannot be turned on.')
    const action = dialog('[data-test="entitlement-action"]')
    expect(action?.textContent?.trim()).toBe('See plans')
    expect(action?.getAttribute('href')).toBe('https://ops.example.test/plans')
    expect(action?.getAttribute('target')).toBe('_blank')
  })

  it('shows a paused Solution that left the plan, and lets it be turned off', async () => {
    const wrapper = mountPage([solution({ active: false, canActivate: false, entitlement: { state: 'not_entitled', paused: true, action: null } })])

    expect(wrapper.get('[data-test="state"]').text()).toBe('Paused')
    expect(wrapper.find('[data-test="activate"]').exists()).toBe(false)

    await openDetails(wrapper)
    expect(dialog('[data-test="entitlement"]')?.textContent).toContain('Paused, data kept.')
    expect(dialog('[data-test="entitlement-action"]')).toBeNull()
    await wrapper.get('[data-test="deactivate"]').trigger('click')
    await flushPromises()
    expect(document.body.textContent).toContain('Turn off Feedback?')
  })

  it('says once that the plan could not be checked', () => {
    const unavailable = { state: 'unavailable' as const, paused: false, action: null }
    const wrapper = mountPage([solution({ canActivate: false, entitlement: unavailable }), solution({ id: 'survey', canActivate: false, entitlement: unavailable })])

    expect(wrapper.findAll('[data-test="entitlement-unavailable"]')).toHaveLength(1)
    expect(wrapper.find('[data-test="not-entitled"]').exists()).toBe(false)
  })

  it('shows an empty state when nothing is installed', () => {
    const wrapper = mountPage([])

    expect(wrapper.text()).toContain('No Solutions are installed')
  })
})
